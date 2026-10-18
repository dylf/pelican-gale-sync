<?php

namespace Dylf\GaleSync\Support;

use Dylf\GaleSync\Core\SyncProgress;
use Dylf\GaleSync\Core\SyncState;

/**
 * Draws the status block at the top of the server page: a banner that says what is going on, a progress bar while a
 * sync runs, and the list of mods with their state. No panel code in here, so it can be tested on its own.
 */
final class StatusRenderer
{
    private const BLUE = '#3b82f6';

    private const GREEN = '#22c55e';

    private const RED = '#ef4444';

    private const AMBER = '#f59e0b';

    private const GRAY = '#9ca3af';

    private const ACTION_COLORS = ['keep' => self::GREEN, 'install' => self::AMBER, 'update' => self::BLUE, 'remove' => self::RED, 'skip' => self::GRAY];

    private const ACTION_SYMBOLS = ['keep' => '✓', 'install' => '+', 'update' => '~', 'remove' => '−', 'skip' => '·'];

    /** Changes first, then what is left out, then what is fine. */
    private const ACTION_ORDER = ['remove' => 0, 'update' => 1, 'install' => 2, 'skip' => 3, 'keep' => 4];

    /** A finished sync's result is shown for this long, then the page goes back to the "up to date" view. */
    private const RESULT_SECONDS = 1800;

    /** @var \Closure(string, array<string, mixed>): string */
    private \Closure $t;

    /**
     * @param  callable(string, array<string, mixed>): string  $translate  turns a message key and its values into text
     */
    public function __construct(callable $translate, private readonly int $now)
    {
        $this->t = \Closure::fromCallable($translate);
    }

    /**
     * @param  array{
     *     has_profile: bool,
     *     state: SyncState,
     *     last_error: ?string,
     *     last_checked: ?string,
     *     last_synced: ?string,
     *     pending: ?array<string, mixed>,
     *     progress: ?SyncProgress,
     * }  $context
     */
    public function render(array $context): string
    {
        $progress = $context['progress'];
        $pending = $context['pending'];
        $hasPending = $pending !== null && ($pending['changes'] ?? false);

        $state = $context['state'];
        $running = $state === SyncState::Running;
        $queued = $state === SyncState::Queued;
        $recent = $progress !== null && $progress->finished() && ($this->now - ($progress->finishedAt ?? 0)) < self::RESULT_SECONDS;

        $live = $state->isActive();

        $html = '<style>@keyframes gs-pulse{0%,100%{opacity:1}50%{opacity:.35}}@keyframes gs-slide{0%{left:-40%}100%{left:100%}}</style>';
        $html .= $this->banner($context, $progress, $pending, $hasPending, $recent);

        // Progress is laid over the profile's list while a sync runs and for a while after it.
        $showProgress = $progress !== null && ($running || $recent || $state === SyncState::Stalled);
        $items = (array) ($pending['items'] ?? []);

        if ($items !== []) {
            $html .= $this->profileList($items, $showProgress ? $progress : null);
        } elseif ($showProgress && $progress->total() > 0) {
            $html .= $this->progressList($progress);
        }

        // While something is happening, ask the page to refresh itself so nobody has to reload.
        return $live ? '<div wire:poll.2s>' . $html . '</div>' : $html;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $pending
     */
    private function banner(array $context, ?SyncProgress $progress, ?array $pending, bool $hasPending, bool $recent): string
    {
        $state = $context['state'];

        if (!$context['has_profile']) {
            return $this->box(self::GRAY, ($this->t)('banner_no_profile', []), '');
        }

        if ($state === SyncState::NeverStarted) {
            return $this->box(self::RED, ($this->t)('banner_stuck_title', []), ($this->t)('banner_stuck_sub', []));
        }

        if ($state === SyncState::Stalled) {
            return $this->box(self::RED, ($this->t)('banner_stalled_title', []), ($this->t)('banner_stalled_sub', ['message' => $progress->message]));
        }

        if ($state === SyncState::Running) {
            $title = $progress->total() > 0
                ? ($this->t)('banner_running_title', ['done' => $progress->handled(), 'total' => $progress->total()])
                : ($this->t)('banner_running_title_unknown', []);

            return $this->box(
                self::BLUE,
                $title,
                $progress->message . ' · ' . $this->duration($progress->elapsed($this->now)),
                $this->bar(self::BLUE, $progress->phase === SyncProgress::MODS ? $progress->percent() : null),
            );
        }

        if ($state === SyncState::Queued) {
            $requested = $this->duration(max(0, $this->now - ($progress->startedAt ?? $this->now)));

            return $this->box(self::BLUE, ($this->t)('banner_queued_title', []), ($this->t)('banner_queued_sub', ['when' => $requested]), $this->bar(self::BLUE, null));
        }

        if ($recent && $progress->phase === SyncProgress::DONE) {
            return $this->box(self::GREEN, ($this->t)('banner_done_title', []), $progress->message . ' · ' . $this->duration($progress->elapsed($this->now)), $this->bar(self::GREEN, 100));
        }

        if ($recent) {
            $problems = '';

            foreach ($progress->items as $name => $item) {
                if ($item['state'] === SyncProgress::ERROR) {
                    $problems .= '<div style="margin-top: .25rem;">' . $this->esc($name . ': ' . $item['error']) . '</div>';
                }
            }

            return $this->box(self::RED, ($this->t)('banner_failed_title', []), $this->esc($progress->message) . ($problems !== '' ? $problems : ''), '', rawSub: true);
        }

        if ($context['last_error']) {
            return $this->box(self::RED, ($this->t)('banner_error_title', []), $context['last_error']);
        }

        if ($hasPending) {
            return $this->box(self::AMBER, ($this->t)('banner_pending_title', []), ($this->t)('banner_pending_sub', ['count' => $this->changeCount($pending), 'when' => (string) $context['last_checked']]));
        }

        $sub = $context['last_synced']
            ? ($this->t)('banner_uptodate_synced', ['profile' => (string) ($pending['profile_name'] ?? ''), 'when' => (string) $context['last_synced']])
            : ($this->t)('banner_uptodate_checked', ['when' => (string) $context['last_checked']]);

        return $this->box(self::GREEN, ($this->t)('banner_uptodate_title', []), $sub);
    }

    private function box(string $color, string $title, string $sub, string $extra = '', bool $rawSub = false): string
    {
        return '<div style="margin: 0 0 1rem; padding: .85rem 1rem; border-radius: .5rem; border-left: 4px solid ' . $color . '; background: ' . $color . '1a;">'
            . '<div style="font-size: 1.05rem; font-weight: 600;">' . $this->esc($title) . '</div>'
            . ($sub !== '' ? '<div style="margin-top: .2rem; font-size: .875rem; opacity: .85;">' . ($rawSub ? $sub : $this->esc($sub)) . '</div>' : '')
            . $extra
            . '</div>';
    }

    /**
     * @param  int|null  $percent  null for a bar that just keeps moving
     */
    private function bar(string $color, ?int $percent): string
    {
        $inner = $percent === null
            ? '<div style="position: absolute; top: 0; bottom: 0; width: 40%; background: ' . $color . '; border-radius: 9999px; animation: gs-slide 1.4s ease-in-out infinite;"></div>'
            : '<div style="height: 100%; width: ' . max(0, min(100, $percent)) . '%; background: ' . $color . '; border-radius: 9999px; transition: width .4s;"></div>';

        return '<div style="position: relative; overflow: hidden; margin-top: .65rem; height: .6rem; border-radius: 9999px; background: rgba(128,128,128,.25);">' . $inner . '</div>'
            . ($percent !== null ? '<div style="margin-top: .2rem; font-size: .75rem; opacity: .75; text-align: right;">' . $percent . '%</div>' : '');
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function chips(array $counts): string
    {
        $chips = '';

        foreach (array_keys(self::ACTION_ORDER) as $action) {
            if (($counts[$action] ?? 0) > 0) {
                $chips .= '<span style="margin-right: .5rem;">' . $this->badge($action, $counts[$action] . ' ' . ($this->t)('label_' . $action, [])) . '</span>';
            }
        }

        return $chips !== '' ? '<p style="margin: 0 0 .5rem;">' . $chips . '</p>' : '';
    }

    /**
     * Every mod of the profile with where it stands. While a sync runs, each row shows its own progress and a mod that
     * has just been installed turns into "up to date", so the list keeps its shape from start to finish.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function profileList(array $items, ?SyncProgress $progress): string
    {
        usort($items, fn (array $a, array $b) => [self::ACTION_ORDER[$a['action']] ?? 9, strtolower($a['name'])] <=> [self::ACTION_ORDER[$b['action']] ?? 9, strtolower($b['name'])]);

        $rows = '';
        $counts = [];

        foreach ($items as $item) {
            $action = $item['action'];
            $state = $progress?->items[$item['name']]['state'] ?? null;
            $error = $progress?->items[$item['name']]['error'] ?? null;

            $version = match ($action) {
                'update' => "{$item['from']} → {$item['to']}",
                'remove' => (string) $item['from'],
                'keep' => (string) ($item['from'] ?? $item['to']),
                default => (string) $item['to'],
            };

            // A mod that has been brought up to date no longer needs the badge of the change that was made.
            $shown = $state === SyncProgress::OK && in_array($action, ['install', 'update'], true) ? 'keep' : $action;
            $note = $error ?: trim(($item['source'] ?: '') . ($item['reason'] ? ' · ' . $item['reason'] : ''), ' ·');

            $counts[$shown] = ($counts[$shown] ?? 0) + 1;
            $rows .= $this->row($shown, $item['name'], $version, $note, $state ?? ($action === 'keep' ? SyncProgress::OK : null), $action === 'skip');
        }

        // Counted from what is shown, so the chips follow the rows as mods are brought up to date.
        return $this->chips($counts) . $this->table($rows);
    }

    private function progressList(SyncProgress $progress): string
    {
        $rows = '';

        foreach ($progress->items as $name => $item) {
            $rows .= $this->row($item['action'], $name, $item['version'] ?? '', $item['state'] === SyncProgress::ERROR ? (string) $item['error'] : '', $item['state']);
        }

        return $this->table($rows);
    }

    private function row(string $action, string $name, string $version, string $note, ?string $state, bool $dim = false): string
    {
        $color = self::ACTION_COLORS[$action] ?? self::GRAY;
        $cell = 'padding: .25rem .75rem .25rem .5rem;';
        $opacity = $dim || $state === SyncProgress::WAITING ? ' opacity: .6;' : '';

        $icon = match ($state) {
            SyncProgress::WAITING => '<span title="' . $this->esc(($this->t)('state_waiting', [])) . '" style="opacity: .6;">○</span>',
            SyncProgress::WORKING => '<span title="' . $this->esc(($this->t)('state_working', [])) . '" style="color: ' . self::BLUE . '; animation: gs-pulse 1s ease-in-out infinite;">●</span>',
            SyncProgress::OK => '<span title="' . $this->esc(($this->t)('state_done', [])) . '" style="color: ' . self::GREEN . '; font-weight: 700;">✓</span>',
            SyncProgress::ERROR => '<span title="' . $this->esc(($this->t)('state_failed', [])) . '" style="color: ' . self::RED . '; font-weight: 700;">✕</span>',
            default => '',
        };

        $noteColor = $state === SyncProgress::ERROR ? ' color: ' . self::RED . ';' : ' opacity: .75;';
        $background = $state === SyncProgress::ERROR ? self::RED : $color;
        $working = $state === SyncProgress::WORKING ? ' font-weight: 600;' : '';
        // Rows that are already fine stay quiet so the ones that need attention stand out.
        $tint = $action === 'keep' ? '0d' : '14';

        return '<tr style="background: ' . $background . $tint . '; box-shadow: inset 3px 0 0 ' . $color . ';' . $opacity . '">'
            . '<td style="' . $cell . ' width: 1.5rem; text-align: center;">' . $icon . '</td>'
            . '<td style="' . $cell . '">' . $this->badge($action) . '</td>'
            . '<td style="' . $cell . ' font-weight: 500;' . $working . '">' . $this->esc($name) . '</td>'
            . '<td style="' . $cell . ' color: ' . $color . ';">' . $this->esc($version) . '</td>'
            . '<td style="' . $cell . $noteColor . '">' . $this->esc($note) . '</td></tr>';
    }

    private function table(string $rows): string
    {
        return '<table style="font-size: .8rem; border-collapse: separate; border-spacing: 0 2px; width: 100%;">' . $rows . '</table>';
    }

    private function badge(string $action, ?string $text = null): string
    {
        $color = self::ACTION_COLORS[$action] ?? self::GRAY;

        return '<span style="display: inline-block; min-width: 5.5rem; padding: .05rem .5rem; border-radius: .375rem; font-weight: 600; text-align: center; color: ' . $color . '; background: ' . $color . '26;">'
            . $this->esc((self::ACTION_SYMBOLS[$action] ?? '') . ' ' . ($text ?? ($this->t)('label_' . $action, []))) . '</span>';
    }

    /**
     * @param  array<string, mixed>|null  $pending
     */
    private function changeCount(?array $pending): int
    {
        $counts = $pending['counts'] ?? [];

        return (int) (($counts['install'] ?? 0) + ($counts['update'] ?? 0) + ($counts['remove'] ?? 0));
    }

    private function duration(int $seconds): string
    {
        return $seconds >= 60 ? intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's' : $seconds . 's';
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
