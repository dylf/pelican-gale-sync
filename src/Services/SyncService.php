<?php

namespace Dylf\GaleSync\Services;

use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use Dylf\GaleSync\Core\InstalledMod;
use Dylf\GaleSync\Core\InstallState;
use Dylf\GaleSync\Core\LayoutPlanner;
use Dylf\GaleSync\Core\ManifestMod;
use Dylf\GaleSync\Core\PlanAction;
use Dylf\GaleSync\Core\PlanBuilder;
use Dylf\GaleSync\Core\PlanItem;
use Dylf\GaleSync\Models\GaleSyncServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use ZipArchive;

class SyncService
{
    public function __construct(
        private readonly GaleClient $client,
        private readonly GamesRegistry $games,
        private readonly PackageSources $sources,
        private readonly DaemonFileRepository $daemonFiles,
    ) {}

    public function files(GaleSyncServer $setting): ServerFiles
    {
        return new ServerFiles(clone $this->daemonFiles, $setting->server);
    }

    public function readState(ServerFiles $files): InstallState
    {
        return InstallState::fromJson($files->read(InstallState::FILE));
    }

    /**
     * Downloads the profile and compares it with the server.
     */
    public function prepare(GaleSyncServer $setting, ?ServerFiles $files = null): Prepared
    {
        $id = $setting->profileId() ?? throw new GaleSyncException('No valid Gale sync ID is set for this server.');
        $files ??= $this->files($setting);

        $meta = $this->client->meta($id);
        $archive = $this->client->archive($id);
        $manifest = $archive->manifest();

        if (!$manifest->community) {
            throw new GaleSyncException('The profile does not say which game it is for.');
        }

        $game = $this->games->find($manifest->community)
            ?? throw new GaleSyncException("Gale does not know a game called '{$manifest->community}'.");

        $rules = $this->games->rules($game)
            ?? throw new GaleSyncException("{$game['name']} uses the {$game['modLoader']['name']} mod loader, which Gale Sync can't install yet.");

        $backends = $this->games->backends($game);

        $candidates = array_values(array_filter($manifest->mods, fn ($mod) => $mod->enabled && !$rules->isLoaderPackage($mod->fullName)));
        $clientOnly = $setting->skip_client_only ? $this->sources->clientOnly($candidates, $manifest->community, $backends) : [];

        $state = $this->readState($files);
        $plan = (new PlanBuilder())->build($manifest, $state, $rules, $clientOnly, $setting->skip_client_only);

        return new Prepared($id, $meta['updated_at'] ?? null, $archive, $manifest, $game, $backends, $rules, $state, $plan);
    }

    /**
     * Looks at the profile and records whether the server is behind it. Cheap when nothing changed.
     */
    public function check(GaleSyncServer $setting, bool $force = false): void
    {
        $id = $setting->profileId();

        if (!$id) {
            return;
        }

        try {
            $files = $this->files($setting);
            $state = $this->readState($files);
            $meta = $this->client->meta($id);

            if (!$force && $meta !== null && $state->profileId === $id->value && $state->profileUpdatedAt === $meta['updated_at']) {
                // The server already follows this version of the profile.
                $setting->forceFill(['last_checked_at' => now(), 'remote_updated_at' => $meta['updated_at'], 'pending' => null, 'last_error' => null])->save();

                return;
            }

            if (!$force && $meta !== null && $setting->pending !== null && $setting->remote_updated_at === $meta['updated_at']) {
                $setting->forceFill(['last_checked_at' => now(), 'last_error' => null])->save();

                return;
            }

            $prepared = $this->prepare($setting, $files);

            $setting->forceFill([
                'last_checked_at' => now(),
                'remote_updated_at' => $prepared->updatedAt,
                'pending' => $this->summary($prepared),
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $setting->forceFill(['last_checked_at' => now(), 'last_error' => $exception->getMessage()])->save();

            if (!$exception instanceof GaleSyncException) {
                report($exception);
            }
        }
    }

    /**
     * Whether a sync would change anything on the server.
     *
     * @param  array<string, mixed>|null  $pending
     */
    public static function hasPending(?array $pending): bool
    {
        return $pending !== null && ($pending['changes'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Prepared $prepared): array
    {
        $profileChanged = $prepared->updatedAt !== null && $prepared->state->profileUpdatedAt !== $prepared->updatedAt;

        return [
            'profile_name' => $prepared->manifest->profileName,
            'game' => $prepared->game['name'] ?? $prepared->community(),
            'updated_at' => $prepared->updatedAt,
            'checked_at' => now()->toIso8601String(),
            'changes' => $prepared->plan->hasChanges() || ($profileChanged && $prepared->state->profileId !== null),
            'counts' => $prepared->plan->counts(),
            'items' => array_map(fn (PlanItem $item) => [
                'name' => $item->fullName,
                'action' => $item->action->value,
                'from' => $item->from,
                'to' => $item->to,
                'source' => $item->source,
                'reason' => $item->reason,
            ], $prepared->plan->with(PlanAction::Install, PlanAction::Update, PlanAction::Remove, PlanAction::Skip)),
        ];
    }

    /**
     * Brings the server in line with the profile. The server must be stopped.
     *
     * @return array{installed: int, updated: int, removed: int, failed: array<string, string>}
     */
    public function apply(GaleSyncServer $setting, ?Closure $progress = null): array
    {
        $progress ??= fn (string $message) => null;
        $lock = Cache::lock('gale-sync:apply:' . $setting->server_id, 1800);

        if (!$lock->get()) {
            throw new GaleSyncException('A sync is already running for this server.');
        }

        try {
            $files = $this->files($setting);
            $progress('Reading the profile');
            $prepared = $this->prepare($setting, $files);

            return $this->execute($setting, $prepared, $files, $progress);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{installed: int, updated: int, removed: int, failed: array<string, string>}
     */
    private function execute(GaleSyncServer $setting, Prepared $prepared, ServerFiles $files, Closure $progress): array
    {
        $state = $prepared->state;
        $result = ['installed' => 0, 'updated' => 0, 'removed' => 0, 'failed' => []];
        $planner = new LayoutPlanner();

        $save = function () use ($state, $files) {
            $files->write(InstallState::FILE, $state->toJson());
        };

        foreach ($prepared->plan->with(PlanAction::Remove) as $item) {
            $progress("Removing {$item->fullName}");

            try {
                $this->remove($state, $item->fullName, $files);
                $save();
                $result['removed']++;
            } catch (Throwable $exception) {
                $result['failed'][$item->fullName] = $exception->getMessage();
            }
        }

        if ($setting->config_mode !== 'none') {
            $progress('Copying the profile\'s config files');

            try {
                $this->syncConfigs($setting, $prepared, $files);
            } catch (Throwable $exception) {
                $result['failed']['config files'] = $exception->getMessage();
            }
        }

        $changes = $prepared->plan->with(PlanAction::Update, PlanAction::Install);

        foreach ($changes as $index => $item) {
            $progress(sprintf('%s %s (%d of %d)', $item->action === PlanAction::Update ? 'Updating' : 'Installing', $item->fullName, $index + 1, count($changes)));

            $mod = $prepared->manifest->find($item->fullName);

            try {
                $source = $this->sources->resolve($mod, $prepared->backends);

                // Forget the old copy first: if this fails half way, the next check sees the mod as missing, not as current.
                $old = $state->mods[$item->fullName] ?? null;
                unset($state->mods[$item->fullName]);

                $installed = $this->install($prepared, $mod, $source, $old, $files, $planner);
                $state->mods[$item->fullName] = $installed;
                $save();

                $result[$item->action === PlanAction::Update ? 'updated' : 'installed']++;
            } catch (Throwable $exception) {
                $result['failed'][$item->fullName] = $exception->getMessage();

                if (!$exception instanceof GaleSyncException) {
                    report($exception);
                }
            }
        }

        if ($result['failed'] === []) {
            $state->profileId = $prepared->id->value;
            $state->profileName = $prepared->manifest->profileName;
            $state->profileUpdatedAt = $prepared->updatedAt;
            $state->syncedAt = now()->toIso8601String();
            $save();
        }

        return $result;
    }

    private function remove(InstallState $state, string $fullName, ServerFiles $files): void
    {
        $installed = $state->mods[$fullName] ?? null;

        if ($installed !== null) {
            $files->delete([...$installed->dirs, ...$installed->files]);
        }

        unset($state->mods[$fullName]);
    }

    private function install(Prepared $prepared, ManifestMod $mod, string $source, ?InstalledMod $old, ServerFiles $files, LayoutPlanner $planner): InstalledMod
    {
        $url = $this->sources->downloadUrl($source, $mod);
        $path = (string) tempnam(sys_get_temp_dir(), 'gale-sync-pkg-');

        try {
            $response = Http::timeout(300)->sink($path)->get($url);

            if (!$response->successful()) {
                throw new GaleSyncException("{$mod->fullName} {$mod->version}: $source answered HTTP {$response->status()}.");
            }

            $zip = new ZipArchive();

            if ($zip->open($path) !== true) {
                throw new GaleSyncException("{$mod->fullName} {$mod->version}: the download from $source is not a zip file.");
            }

            try {
                $entries = [];

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);

                    if (!str_ends_with($name, '/') && !str_ends_with($name, '\\')) {
                        $entries[] = $name;
                    }
                }

                $layout = $planner->plan($prepared->rules, $mod->fullName, $entries);

                // Replace the mod's folders as a whole so files a newer version dropped don't linger.
                $files->delete([...($old?->dirs ?? []), ...($old?->files ?? []), ...$layout->dirs]);

                foreach ($layout->moves as $move) {
                    if ($move['config'] && $files->exists($move['to'])) {
                        continue;
                    }

                    $files->write($move['to'], (string) $zip->getFromName($move['from']));
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        return new InstalledMod($mod->version, $source, $layout->dirs, $layout->files);
    }

    private function syncConfigs(GaleSyncServer $setting, Prepared $prepared, ServerFiles $files): void
    {
        foreach ($prepared->archive->configFiles($prepared->rules->configDirs, (array) config('gale-sync.ignored_config')) as $entry) {
            $target = str_replace('\\', '/', $entry);
            $content = $prepared->archive->read($entry);
            $existing = $files->exists($target);

            if ($existing && $setting->config_mode === 'missing') {
                continue;
            }

            if ($existing && $files->read($target) === $content) {
                continue;
            }

            $files->write($target, $content);
        }
    }
}
