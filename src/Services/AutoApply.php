<?php

namespace Dylf\GaleSync\Services;

use App\Enums\ContainerStatus;
use Dylf\GaleSync\Core\SyncProgress;
use Dylf\GaleSync\Core\SyncState;
use Dylf\GaleSync\Models\GaleSyncServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled part: looks at every configured server's profile and applies updates the admin allowed to be automatic.
 */
class AutoApply
{
    public const REQUEST_KEY = 'gale-sync:request:';

    public function __construct(
        private readonly SyncService $service,
        private readonly ServerPower $power,
        private readonly SyncRunner $runner,
        private readonly ProgressStore $progressStore,
    ) {}

    /**
     * Remembers that an admin asked for a sync, for the next run of the scheduled command to pick up.
     */
    public static function request(GaleSyncServer $setting, bool $stopFirst, bool $startAfter): void
    {
        Cache::put(self::REQUEST_KEY . $setting->id, ['stop_first' => $stopFirst, 'start_after' => $startAfter], now()->addMinutes(30));
        app(ProgressStore::class)->put($setting->id, new SyncProgress(SyncProgress::QUEUED, trans('gale-sync::messages.queued'), time()));
    }

    /**
     * @return array<int, string> server id => what was decided, for logging
     */
    public function run(bool $force = false): array
    {
        $results = [];

        $settings = GaleSyncServer::query()->whereNotNull('profile')->with('server')->get();

        // Syncs an admin asked for with "Sync now" come first.
        foreach ($settings as $setting) {
            $request = Cache::pull(self::REQUEST_KEY . $setting->id);

            // A request that waited too long was already reported as "never started"; don't run it now.
            if ($request === null || $this->progressStore->state($setting->id) !== SyncState::Queued) {
                continue;
            }

            $this->runner->run($setting, (bool) ($request['stop_first'] ?? false), (bool) ($request['start_after'] ?? false));
            $results[$setting->server_id] = 'synced on request';
        }

        foreach ($settings as $setting) {
            $results[$setting->server_id] ??= $this->handle($setting, $force);
        }

        return $results;
    }

    public function handle(GaleSyncServer $setting, bool $force = false): string
    {
        $server = $setting->server;
        $id = $setting->profileId();

        if (!$id) {
            return 'skipped: no valid profile';
        }

        if ($server->isSuspended() || $server->isInConflictState()) {
            return 'skipped: server is suspended or busy';
        }

        if ($this->progressStore->state($setting->id)->isActive()) {
            return 'skipped: a sync is running or requested';
        }

        // A profile code never changes, so only a sync ID is followed on a timer.
        $due = $force || !$setting->last_checked_at || ($id->isSync() && $setting->last_checked_at->lte(now()->subMinutes((int) config('gale-sync.check_interval', 5))));

        if ($due) {
            $this->service->check($setting);
            $setting->refresh();
        }

        if (!SyncService::hasPending($setting->pending)) {
            return $setting->last_error ? 'error: ' . $setting->last_error : 'up to date';
        }

        if (!$setting->auto_apply_stopped && !$setting->auto_apply_empty) {
            return 'update waiting for approval';
        }

        if ($setting->auto_apply_stopped && $this->power->isStopped($server)) {
            Log::info("gale-sync: applying a profile update to stopped server {$server->uuid}");
            $this->runner->run($setting);

            return 'applied (server stopped)';
        }

        if ($setting->auto_apply_empty && $this->power->status($server) === ContainerStatus::Running) {
            if ($this->power->players($server) === 0) {
                Log::info("gale-sync: applying a profile update to empty server {$server->uuid}");
                $this->runner->run($setting, stopFirst: true, startAfter: true);

                return 'applied (server empty)';
            }

            return 'update waiting: players online or count unknown';
        }

        return 'update waiting: server is busy';
    }
}
