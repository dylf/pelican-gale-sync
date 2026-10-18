<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\SyncProgress;
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
    public function __construct(
        private readonly SyncService $service,
        private readonly ServerPower $power,
        private readonly ProgressStore $progressStore,
    ) {}

    /**
     * @param  bool  $stopFirst  stop the server if it is running (mods can't change under a running game)
     * @param  bool  $startAfter  start it again afterwards; only done if this run stopped it
     */
    public function run(GaleSyncServer $setting, bool $stopFirst = false, bool $startAfter = false): void
    {
        // Only one process syncs a server at a time. The lease is renewed by every progress write and lapses by itself
        // if this process dies.
        if (!$this->progressStore->claim($setting->id)) {
            return;
        }

        $server = $setting->server;
        $progress = new SyncProgress(startedAt: time());
        $report = fn () => $this->progressStore->put($setting->id, $progress);

        $progress->setPhase(SyncProgress::READING, trans('gale-sync::messages.phase_reading'));
        $report();

        $stopped = false;

        try {
            if (!$this->power->isStopped($server)) {
                if (!$stopFirst) {
                    throw new GaleSyncException(trans('gale-sync::messages.stop_first'));
                }

                $progress->setPhase(SyncProgress::STOPPING, trans('gale-sync::messages.phase_stopping'));
                $report();

                $this->power->stop($server, (int) config('gale-sync.stop_timeout', 90));
                $stopped = true;
            }

            $result = $this->service->apply($setting, $progress);

            if ($startAfter && $stopped) {
                $progress->setPhase(SyncProgress::STARTING, trans('gale-sync::messages.phase_starting'));
                $report();

                $this->power->start($server);
            }

            $summary = sprintf('%d installed, %d updated, %d removed', $result['installed'], $result['updated'], $result['removed']);

            if ($result['failed'] !== []) {
                $setting->forceFill(['last_synced_at' => now()])->save();
                $this->finish($setting, $progress, SyncProgress::FAILED, trans('gale-sync::messages.phase_failures', ['count' => count($result['failed'])]));

                // Show what is still missing, not the plan from before the sync.
                $this->service->check($setting, force: true);
            } else {
                $setting->forceFill(['last_synced_at' => now(), 'pending' => null, 'last_error' => null])->save();
                $this->finish($setting, $progress, SyncProgress::DONE, $summary);
            }

            Log::info("gale-sync: synced server {$server->uuid} ($summary)");
        } catch (Throwable $exception) {
            if (!$exception instanceof GaleSyncException) {
                report($exception);
            }

            $this->finish($setting, $progress, SyncProgress::FAILED, $exception->getMessage());

            // The server was stopped for this sync; don't leave it down because the sync failed.
            if ($stopped && $startAfter) {
                try {
                    $this->power->start($server);
                } catch (Throwable) {
                    // Nothing more we can do; the failure is on the page.
                }
            }
        } finally {
            $this->progressStore->release($setting->id);
        }
    }

    private function finish(GaleSyncServer $setting, SyncProgress $progress, string $phase, string $message): void
    {
        $progress->setPhase($phase, $message);
        $progress->finishedAt = time();
        $this->progressStore->put($setting->id, $progress);
    }
}
