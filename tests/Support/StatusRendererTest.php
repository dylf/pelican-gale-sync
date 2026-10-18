<?php

namespace Dylf\GaleSync\Tests\Support;

use Dylf\GaleSync\Core\SyncProgress;
use Dylf\GaleSync\Support\StatusRenderer;
use PHPUnit\Framework\TestCase;

class StatusRendererTest extends TestCase
{
    private const NOW = 10_000;

    /** @var list<string> */
    private array $keys = [];

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function render(array $overrides = []): string
    {
        $renderer = new StatusRenderer(function (string $key, array $values) {
            $this->keys[] = $key;

            return $key . ($values === [] ? '' : '[' . implode(',', array_map(fn ($name, $value) => "$name=$value", array_keys($values), $values)) . ']');
        }, self::NOW);

        return $renderer->render($overrides + [
            'has_profile' => true,
            'status' => 'idle',
            'stuck' => false,
            'status_since' => null,
            'last_error' => null,
            'last_checked' => '1 minute ago',
            'last_synced' => null,
            'pending' => null,
            'progress' => null,
        ]);
    }

    private function pending(): array
    {
        return [
            'profile_name' => 'Example Profile',
            'changes' => true,
            'counts' => ['install' => 2, 'update' => 1, 'remove' => 0, 'keep' => 5, 'skip' => 1],
            'items' => [
                ['name' => 'A-One', 'action' => 'install', 'from' => null, 'to' => '1.0.0', 'source' => 'Hexium', 'reason' => null],
                ['name' => 'B-Two', 'action' => 'update', 'from' => '1.0.0', 'to' => '1.1.0', 'source' => 'Thunderstore', 'reason' => null],
                ['name' => 'C-Client', 'action' => 'skip', 'from' => null, 'to' => '1.0.0', 'source' => 'Hexium', 'reason' => 'client-side only'],
            ],
        ];
    }

    private function running(): SyncProgress
    {
        $progress = new SyncProgress(SyncProgress::MODS, 'Installing B-Two', self::NOW - 75);
        $progress->plan([
            ['name' => 'A-One', 'action' => 'install', 'version' => '1.0.0'],
            ['name' => 'B-Two', 'action' => 'update', 'version' => '1.1.0'],
            ['name' => 'D-Four', 'action' => 'install', 'version' => '4.0.0'],
            ['name' => 'E-Five', 'action' => 'install', 'version' => '5.0.0'],
        ]);
        $progress->finish('A-One');
        $progress->start('B-Two');

        return $progress;
    }

    public function test_without_a_profile_it_asks_for_one(): void
    {
        $this->assertStringContainsString('banner_no_profile', $this->render(['has_profile' => false]));
    }

    public function test_an_available_update_is_a_banner_and_a_colored_list(): void
    {
        $html = $this->render(['pending' => $this->pending()]);

        $this->assertStringContainsString('banner_pending_title', $html);
        $this->assertStringContainsString('count=3', $html, 'installs, updates and removals are counted; skips are not');
        $this->assertStringContainsString('A-One', $html);
        $this->assertStringContainsString('1.0.0 → 1.1.0', $html);
        $this->assertStringContainsString('client-side only', $html);
        $this->assertStringNotContainsString('wire:poll', $html, 'nothing is happening, so nothing needs refreshing');
    }

    public function test_a_running_sync_shows_progress_and_refreshes_itself(): void
    {
        $html = $this->render(['status' => 'syncing', 'progress' => $this->running()]);

        $this->assertStringContainsString('wire:poll.2s', $html);
        $this->assertStringContainsString('banner_running_title[done=1,total=4]', $html);
        $this->assertStringContainsString('Installing B-Two · 1m 15s', $html);
        $this->assertStringContainsString('width: 25%', $html);
        $this->assertStringContainsString('state_done', $html);
        $this->assertStringContainsString('state_working', $html);
        $this->assertStringContainsString('state_waiting', $html);
    }

    public function test_before_the_mods_are_known_the_bar_just_moves(): void
    {
        $progress = new SyncProgress(SyncProgress::READING, 'Reading the profile', self::NOW - 3);

        $html = $this->render(['status' => 'syncing', 'progress' => $progress]);

        $this->assertStringContainsString('banner_running_title_unknown', $html);
        $this->assertStringContainsString('gs-slide', $html);
        $this->assertStringNotContainsString('%</div>', $html, 'no percentage can be given yet');
    }

    public function test_a_requested_sync_says_it_is_waiting_for_the_scheduler(): void
    {
        $html = $this->render(['status' => 'queued', 'status_since' => self::NOW - 20]);

        $this->assertStringContainsString('banner_queued_title', $html);
        $this->assertStringContainsString('when=20s', $html);
        $this->assertStringContainsString('wire:poll.2s', $html);
    }

    public function test_a_request_nothing_picked_up_points_at_the_scheduler(): void
    {
        $html = $this->render(['status' => 'queued', 'stuck' => true]);

        $this->assertStringContainsString('banner_stuck_title', $html);
        $this->assertStringNotContainsString('wire:poll', $html);
    }

    public function test_a_finished_sync_shows_its_result_for_a_while(): void
    {
        $progress = $this->running();
        $progress->finish('B-Two');
        $progress->finish('D-Four');
        $progress->finish('E-Five');
        $progress->setPhase(SyncProgress::DONE, '3 installed, 1 updated, 0 removed');
        $progress->finishedAt = self::NOW - 60;

        $html = $this->render(['progress' => $progress]);

        $this->assertStringContainsString('banner_done_title', $html);
        $this->assertStringContainsString('3 installed, 1 updated, 0 removed · 15s', $html);
        $this->assertStringContainsString('width: 100%', $html);

        $this->assertStringNotContainsString('banner_done_title', $this->render(['progress' => $this->finishedAgo($progress, 3600)]), 'an old result gives way to the up to date view');
    }

    private function finishedAgo(SyncProgress $progress, int $seconds): SyncProgress
    {
        $progress->finishedAt = self::NOW - $seconds;

        return $progress;
    }

    public function test_a_failed_mod_shows_why(): void
    {
        $progress = $this->running();
        $progress->finish('B-Two');
        $progress->fail('D-Four', 'Could not resolve host: node.example.com');
        $progress->finish('E-Five');
        $progress->setPhase(SyncProgress::FAILED, '1 mod(s) could not be installed');
        $progress->finishedAt = self::NOW - 5;

        $html = $this->render(['status' => 'failed', 'progress' => $progress]);

        $this->assertStringContainsString('banner_failed_title', $html);
        $this->assertStringContainsString('D-Four: Could not resolve host: node.example.com', $html);
        $this->assertStringContainsString('state_failed', $html);
    }

    public function test_text_from_outside_is_escaped(): void
    {
        $progress = new SyncProgress(SyncProgress::MODS, '<script>alert(1)</script>', self::NOW);
        $progress->plan([['name' => '<b>Evil</b>', 'action' => 'install', 'version' => '"1"']]);

        $html = $this->render(['status' => 'syncing', 'progress' => $progress]);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt;', $html);
    }

    public function test_an_up_to_date_server_is_a_green_banner(): void
    {
        $html = $this->render(['last_synced' => '5 minutes ago', 'pending' => ['profile_name' => 'Example Profile', 'changes' => false, 'counts' => [], 'items' => []]]);

        $this->assertStringContainsString('banner_uptodate_title', $html);
        $this->assertStringContainsString('banner_uptodate_synced', $html);
    }

    public function test_every_message_it_asks_for_exists_in_the_language_file(): void
    {
        $lang = require __DIR__ . '/../../lang/en/messages.php';

        $this->render(['has_profile' => false]);
        $this->render(['stuck' => true, 'status' => 'queued']);
        $this->render(['status' => 'queued', 'status_since' => self::NOW - 5]);
        $this->render(['pending' => $this->pending()]);
        $this->render(['status' => 'syncing', 'progress' => $this->running()]);
        $this->render(['status' => 'syncing', 'progress' => new SyncProgress(SyncProgress::READING, 'x', self::NOW)]);
        $this->render(['last_error' => 'boom']);
        $this->render(['last_synced' => 'now']);
        $this->render();

        $done = $this->running();
        $done->setPhase(SyncProgress::DONE, 'ok');
        $done->finishedAt = self::NOW;
        $this->render(['progress' => $done]);

        $failed = $this->running();
        $failed->fail('A-One', 'e');
        $failed->setPhase(SyncProgress::FAILED, 'x');
        $failed->finishedAt = self::NOW;
        $this->render(['progress' => $failed]);

        foreach (array_unique($this->keys) as $key) {
            $this->assertArrayHasKey($key, $lang, "StatusRenderer asks for '$key' but lang/en/messages.php has no such message");
        }
    }
}
