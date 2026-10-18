<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\SyncProgress;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a server's sync progress where both the scheduled process doing the work and the page showing it can see it.
 */
class ProgressStore
{
    public function get(int $settingId): ?SyncProgress
    {
        $data = Cache::get($this->key($settingId));

        return is_array($data) ? SyncProgress::fromArray($data) : null;
    }

    public function put(int $settingId, SyncProgress $progress): void
    {
        Cache::put($this->key($settingId), $progress->toArray(), now()->addDays(7));
    }

    public function forget(int $settingId): void
    {
        Cache::forget($this->key($settingId));
    }

    private function key(int $settingId): string
    {
        return "gale-sync:progress:$settingId";
    }
}
