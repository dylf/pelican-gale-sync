<?php

namespace Dylf\GaleSync\Services;

use App\Enums\ContainerStatus;
use Dylf\GaleSync\Jobs\ApplySyncJob;
use Dylf\GaleSync\Models\GaleSyncServer;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled part: looks at every configured server's profile and applies updates the admin allowed to be automatic.
 */
class AutoApply
{
    public function __construct(private readonly SyncService $service, private readonly ServerPower $power) {}

    /**
     * @return array<int, string> server id => what was decided, for logging
     */
    public function run(bool $force = false): array
    {
        $results = [];

        GaleSyncServer::query()->whereNotNull('profile')->with('server')->each(function (GaleSyncServer $setting) use (&$results, $force) {
            $results[$setting->server_id] = $this->handle($setting, $force);
        });

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

        if ($setting->isSyncing()) {
            return 'skipped: a sync is running';
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
            ApplySyncJob::dispatch($setting->id);
            Log::info("gale-sync: applying a profile update to stopped server {$server->uuid}");

            return 'applying (server stopped)';
        }

        if ($setting->auto_apply_empty && $this->power->status($server) === ContainerStatus::Running) {
            if ($this->power->players($server) === 0) {
                ApplySyncJob::dispatch($setting->id, stopFirst: true, startAfter: true);
                Log::info("gale-sync: applying a profile update to empty server {$server->uuid}");

                return 'applying (server empty)';
            }

            return 'update waiting: players online or count unknown';
        }

        return 'update waiting: server is busy';
    }
}
