<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\SyncProgress;
use PHPUnit\Framework\TestCase;

class SyncProgressTest extends TestCase
{
    private function progress(): SyncProgress
    {
        $progress = new SyncProgress(startedAt: 1000);
        $progress->plan([
            ['name' => 'A-One', 'action' => 'install', 'version' => '1.0.0'],
            ['name' => 'B-Two', 'action' => 'update', 'version' => '2.0.0'],
            ['name' => 'C-Gone', 'action' => 'remove', 'version' => null],
            ['name' => 'D-Four', 'action' => 'install', 'version' => '4.0.0'],
        ]);

        return $progress;
    }

    public function test_every_planned_mod_starts_out_waiting(): void
    {
        $progress = $this->progress();

        $this->assertSame(4, $progress->total());
        $this->assertSame(0, $progress->handled());
        $this->assertSame(0, $progress->percent());
        $this->assertSame('waiting', $progress->items['A-One']['state']);
    }

    public function test_percent_follows_the_mods_handled_whether_they_worked_or_not(): void
    {
        $progress = $this->progress();

        $progress->start('A-One');
        $this->assertSame('working', $progress->items['A-One']['state']);
        $this->assertSame(0, $progress->percent(), 'a mod being worked on is not done yet');

        $progress->finish('A-One');
        $this->assertSame(25, $progress->percent());

        $progress->fail('B-Two', 'HTTP 404');
        $this->assertSame(50, $progress->percent());
        $this->assertSame(1, $progress->failures());
        $this->assertSame('HTTP 404', $progress->items['B-Two']['error']);
    }

    public function test_percent_is_unknown_until_the_mods_are_known_and_full_when_done(): void
    {
        $progress = new SyncProgress();
        $this->assertNull($progress->percent());

        $progress->setPhase(SyncProgress::DONE);
        $this->assertSame(100, $progress->percent());
    }

    public function test_elapsed_time_stops_when_the_sync_finishes(): void
    {
        $progress = $this->progress();

        $this->assertSame(30, $progress->elapsed(1030));

        $progress->finishedAt = 1072;
        $this->assertSame(72, $progress->elapsed(9999));
    }

    public function test_it_survives_a_round_trip_through_the_cache(): void
    {
        $progress = $this->progress();
        $progress->setPhase(SyncProgress::MODS, 'Installing A-One');
        $progress->finish('A-One');
        $progress->summary = ['installed' => 1];

        $this->assertEquals($progress, SyncProgress::fromArray($progress->toArray()));
    }

    public function test_unknown_mods_are_ignored(): void
    {
        $progress = $this->progress();

        $progress->finish('Nobody-Here');

        $this->assertSame(0, $progress->handled());
    }

    public function test_a_finished_sync_is_done_or_failed(): void
    {
        $progress = new SyncProgress();
        $this->assertFalse($progress->finished());

        $progress->setPhase(SyncProgress::FAILED, 'x');
        $this->assertTrue($progress->finished());
    }
}
