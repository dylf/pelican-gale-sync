<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\SyncProgress;
use Dylf\GaleSync\Core\SyncState;
use PHPUnit\Framework\TestCase;

class SyncStateTest extends TestCase
{
    private const NOW = 5000;

    private function state(?SyncProgress $progress, bool $alive = false): SyncState
    {
        return SyncState::resolve($progress, $alive, self::NOW, 300);
    }

    public function test_with_nothing_recorded_it_is_idle(): void
    {
        $this->assertSame(SyncState::Idle, $this->state(null));
    }

    public function test_a_finished_sync_is_idle_whether_it_worked_or_not(): void
    {
        $this->assertSame(SyncState::Idle, $this->state(new SyncProgress(SyncProgress::DONE)));
        $this->assertSame(SyncState::Idle, $this->state(new SyncProgress(SyncProgress::FAILED)));
    }

    public function test_a_fresh_request_is_queued_and_blocks_another(): void
    {
        $state = $this->state(new SyncProgress(SyncProgress::QUEUED, startedAt: self::NOW - 40));

        $this->assertSame(SyncState::Queued, $state);
        $this->assertTrue($state->isActive());
    }

    public function test_a_request_nothing_picked_up_is_never_started_and_does_not_block(): void
    {
        $state = $this->state(new SyncProgress(SyncProgress::QUEUED, startedAt: self::NOW - 301));

        $this->assertSame(SyncState::NeverStarted, $state);
        $this->assertFalse($state->isActive(), 'the scheduler may be fixed; asking again must be possible');
    }

    public function test_a_sync_whose_process_is_reporting_is_running(): void
    {
        $state = $this->state(new SyncProgress(SyncProgress::MODS, startedAt: self::NOW - 90), alive: true);

        $this->assertSame(SyncState::Running, $state);
        $this->assertTrue($state->isActive());
    }

    public function test_a_sync_whose_process_went_quiet_is_stalled_and_does_not_block(): void
    {
        foreach ([SyncProgress::READING, SyncProgress::STOPPING, SyncProgress::CONFIG, SyncProgress::MODS, SyncProgress::STARTING] as $phase) {
            $state = $this->state(new SyncProgress($phase, startedAt: self::NOW - 900), alive: false);

            $this->assertSame(SyncState::Stalled, $state, $phase);
            $this->assertFalse($state->isActive());
        }
    }

    public function test_a_queued_request_does_not_need_a_live_process(): void
    {
        $this->assertSame(SyncState::Queued, $this->state(new SyncProgress(SyncProgress::QUEUED, startedAt: self::NOW), alive: false));
    }

    public function test_a_progress_is_running_only_between_the_request_and_the_finish(): void
    {
        $this->assertFalse((new SyncProgress(SyncProgress::QUEUED))->running());
        $this->assertTrue((new SyncProgress(SyncProgress::MODS))->running());
        $this->assertFalse((new SyncProgress(SyncProgress::DONE))->running());
    }
}
