<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Models\GaleSyncServer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs one server with its Gale profile: optionally stops it first, and starts it again afterwards.
 *
 * Runs inside the scheduled `gale-sync:check` command, which is a fresh process every minute, so it always runs the
 * plugin's current code (a queue worker keeps the code it started with and has to be restarted after a plugin update).
 */
class SyncRunner
{
    public function __construct(private readonly SyncService $service, private readonly ServerPower $power) {}

    /**
     * @param  bool  $stopFirst  stop the server if it is running (mods can't change under a running game)
     * @param  bool  $startAfter  start it again afterwards; only done if this run stopped it
     */
    public function run(GaleSyncServer $setting, bool $stopFirst = false, bool $startAfter = false): void
    {
        $server = $setting->server;
        $setting->setStatus(GaleSyncServer::SYNCING, 'Starting');

        $stopped = false;

        try {
            if (!$this->power->isStopped($server)) {
                if (!$stopFirst) {
                    throw new GaleSyncException('Stop the server before syncing: its mods can not change while it runs.');
                }

                $setting->setStatus(GaleSyncServer::SYNCING, 'Stopping the server');
                $this->power->stop($server, (int) config('gale-sync.stop_timeout', 90));
                $stopped = true;
            }

            $result = $this->service->apply($setting, function (string $message) use ($setting) {
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

            if ($startAfter && $stopped) {
                $this->power->start($server);
            }
        } catch (SyncAlreadyRunningException) {
            // Another process is doing this sync; leave its status alone.
            return;
        } catch (Throwable $exception) {
            if (!$exception instanceof GaleSyncException) {
                report($exception);
            }

            $setting->setStatus(GaleSyncServer::FAILED, $exception->getMessage());

            // The server was stopped for this sync; don't leave it down because the sync failed.
            if ($stopped && $startAfter) {
                try {
                    $this->power->start($server);
                } catch (Throwable) {
                    // Nothing more we can do; the failure is on the page.
                }
            }
        }
    }
}
