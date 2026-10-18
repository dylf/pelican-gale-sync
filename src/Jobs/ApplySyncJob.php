<?php

namespace Dylf\GaleSync\Jobs;

use Dylf\GaleSync\Models\GaleSyncServer;
use Dylf\GaleSync\Services\GaleSyncException;
use Dylf\GaleSync\Services\ServerPower;
use Dylf\GaleSync\Services\SyncAlreadyRunningException;
use Dylf\GaleSync\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs one server with its Gale profile: optionally stops it first, and starts it again afterwards.
 */
class ApplySyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly int $settingId,
        /** Stop the server if it is running (Valheim can't load mods that change under it). */
        public readonly bool $stopFirst = false,
        /** Start the server again afterwards. */
        public readonly bool $startAfter = false,
    ) {}

    public function handle(SyncService $service, ServerPower $power): void
    {
        $setting = GaleSyncServer::query()->with('server')->find($this->settingId);

        if (!$setting || !$setting->profile) {
            return;
        }

        $server = $setting->server;
        $setting->setStatus(GaleSyncServer::SYNCING, 'Starting');

        $stopped = false;

        try {
            if (!$power->isStopped($server)) {
                if (!$this->stopFirst) {
                    throw new GaleSyncException('Stop the server before syncing: its mods can not change while it runs.');
                }

                $setting->setStatus(GaleSyncServer::SYNCING, 'Stopping the server');
                $power->stop($server, (int) config('gale-sync.stop_timeout', 90));
                $stopped = true;
            }

            $result = $service->apply($setting, function (string $message) use ($setting) {
                $setting->setStatus(GaleSyncServer::SYNCING, $message);
            });

            $summary = sprintf('%d installed, %d updated, %d removed', $result['installed'], $result['updated'], $result['removed']);

            if ($result['failed'] !== []) {
                $failures = collect($result['failed'])->map(fn ($error, $name) => "$name: $error")->implode('; ');
                $setting->forceFill(['last_synced_at' => now()])->save();
                $setting->setStatus(GaleSyncServer::FAILED, "$summary, but some failed: $failures");
            } else {
                $setting->forceFill(['last_synced_at' => now(), 'pending' => null, 'last_error' => null])->save();
                $setting->setStatus(GaleSyncServer::IDLE, $summary);
            }

            Log::info("gale-sync: synced server {$server->uuid} ($summary)");

            if ($this->startAfter && $stopped) {
                $power->start($server);
            }
        } catch (SyncAlreadyRunningException) {
            // Another job is doing this sync; leave its status alone.
            return;
        } catch (Throwable $exception) {
            if (!$exception instanceof GaleSyncException) {
                report($exception);
            }

            $setting->setStatus(GaleSyncServer::FAILED, $exception->getMessage());

            // The server was stopped for this sync; don't leave it down because the sync failed.
            if ($stopped && $this->startAfter) {
                try {
                    $power->start($server);
                } catch (Throwable) {
                    // Nothing more we can do; the failure is on the page.
                }
            }
        }
    }
}
