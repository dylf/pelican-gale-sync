<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\SyncProgress;
use Dylf\GaleSync\Core\SyncState;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a server's sync progress where both the scheduled process doing the work and the page showing it can see it,
 * together with a short lease that says the process is alive.
 *
 * Every write of a running sync renews the lease. If the process dies the lease runs out on its own, so the page and the
 * scheduler find out without anyone having to clear anything.
 */
class ProgressStore
{
    /** Seconds a running sync may go without reporting before it counts as dead. One mod is written per step, so this is generous. */
    public const LEASE_SECONDS = 300;

    public function get(int $settingId): ?SyncProgress
    {
        $data = Cache::get($this->key($settingId));

        return is_array($data) ? SyncProgress::fromArray($data) : null;
    }

    /**
     * Saves progress. Writing a running sync's progress is also its heartbeat; finishing one releases the lease.
     */
    public function put(int $settingId, SyncProgress $progress): void
    {
        Cache::put($this->key($settingId), $progress->toArray(), now()->addDays(7));

        if ($progress->running()) {
            Cache::put($this->leaseKey($settingId), true, now()->addSeconds(self::LEASE_SECONDS));
        } elseif ($progress->finished()) {
            $this->release($settingId);
        }
    }

    /**
     * Takes the right to run this server's sync. Only one process can hold it.
     */
    public function claim(int $settingId): bool
    {
        return Cache::add($this->leaseKey($settingId), true, now()->addSeconds(self::LEASE_SECONDS));
    }

    public function release(int $settingId): void
    {
        Cache::forget($this->leaseKey($settingId));
    }

    public function state(int $settingId): SyncState
    {
        return SyncState::resolve(
            $this->get($settingId),
            Cache::has($this->leaseKey($settingId)),
            time(),
            (int) config('gale-sync.queue_wait_minutes', 5) * 60,
        );
    }

    private function key(int $settingId): string
    {
        return "gale-sync:progress:$settingId";
    }

    private function leaseKey(int $settingId): string
    {
        return "gale-sync:lease:$settingId";
    }
}
