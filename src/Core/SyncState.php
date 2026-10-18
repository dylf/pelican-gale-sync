<?php

namespace Dylf\GaleSync\Core;

/**
 * What a server's sync is doing right now. Worked out from the progress record and whether the process doing the work
 * is still reporting, never stored, so it can't get stuck: when a process dies, the state corrects itself.
 */
enum SyncState: string
{
    case Idle = 'idle';

    /** Requested; waiting for the scheduler's next run to pick it up. */
    case Queued = 'queued';

    case Running = 'running';

    /** Started, but stopped reporting: the process died. */
    case Stalled = 'stalled';

    /** Requested, and nothing picked it up in time: the scheduler isn't running. */
    case NeverStarted = 'never_started';

    /**
     * @param  bool  $alive  whether the process doing the sync has reported recently
     * @param  int  $queueWait  seconds a request may wait for the scheduler
     */
    public static function resolve(?SyncProgress $progress, bool $alive, int $now, int $queueWait): self
    {
        if ($progress === null || $progress->finished()) {
            return self::Idle;
        }

        if ($progress->phase === SyncProgress::QUEUED) {
            return $now - ($progress->startedAt ?? $now) <= $queueWait ? self::Queued : self::NeverStarted;
        }

        return $alive ? self::Running : self::Stalled;
    }

    /**
     * Whether a new sync must wait.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
