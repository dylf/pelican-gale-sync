<?php

namespace Dylf\GaleSync\Core;

/**
 * How far a sync has got: which phase it is in and, for every mod it will touch, whether it is waiting, working, done
 * or failed. Kept in the cache so the server page can show it live while a scheduled process does the work.
 */
final class SyncProgress
{
    public const QUEUED = 'queued';

    public const STOPPING = 'stopping';

    public const READING = 'reading';

    public const CONFIG = 'config';

    public const MODS = 'mods';

    public const STARTING = 'starting';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const WAITING = 'waiting';

    public const WORKING = 'working';

    public const OK = 'ok';

    public const ERROR = 'error';

    /**
     * @param  array<string, array{action: string, version: ?string, state: string, error: ?string}>  $items  by `Owner-Name`, in the order they are done
     * @param  array<string, int>  $summary  installed, updated, removed
     */
    public function __construct(
        public string $phase = self::QUEUED,
        public string $message = '',
        public ?int $startedAt = null,
        public ?int $finishedAt = null,
        public array $items = [],
        public array $summary = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['phase'] ?? self::QUEUED),
            (string) ($data['message'] ?? ''),
            isset($data['started_at']) ? (int) $data['started_at'] : null,
            isset($data['finished_at']) ? (int) $data['finished_at'] : null,
            (array) ($data['items'] ?? []),
            (array) ($data['summary'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'message' => $this->message,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'items' => $this->items,
            'summary' => $this->summary,
        ];
    }

    public function setPhase(string $phase, string $message = ''): void
    {
        $this->phase = $phase;
        $this->message = $message;
    }

    /**
     * @param  list<array{name: string, action: string, version: ?string}>  $items  what the sync is going to do, in order
     */
    public function plan(array $items): void
    {
        $this->items = [];

        foreach ($items as $item) {
            $this->items[$item['name']] = ['action' => $item['action'], 'version' => $item['version'], 'state' => self::WAITING, 'error' => null];
        }
    }

    public function start(string $name): void
    {
        $this->set($name, self::WORKING);
    }

    public function finish(string $name): void
    {
        $this->set($name, self::OK);
    }

    public function fail(string $name, string $error): void
    {
        $this->set($name, self::ERROR, $error);
    }

    public function finished(): bool
    {
        return in_array($this->phase, [self::DONE, self::FAILED], true);
    }

    public function total(): int
    {
        return count($this->items);
    }

    /**
     * Mods dealt with so far, successfully or not.
     */
    public function handled(): int
    {
        return count(array_filter($this->items, fn (array $item) => in_array($item['state'], [self::OK, self::ERROR], true)));
    }

    public function failures(): int
    {
        return count(array_filter($this->items, fn (array $item) => $item['state'] === self::ERROR));
    }

    /**
     * 0 to 100, or null while it can't be told (before the list of mods is known).
     */
    public function percent(): ?int
    {
        if ($this->phase === self::DONE) {
            return 100;
        }

        if ($this->total() === 0) {
            return null;
        }

        return (int) floor($this->handled() / $this->total() * 100);
    }

    public function elapsed(int $now): int
    {
        if ($this->startedAt === null) {
            return 0;
        }

        return max(0, ($this->finishedAt ?? $now) - $this->startedAt);
    }

    private function set(string $name, string $state, ?string $error = null): void
    {
        if (isset($this->items[$name])) {
            $this->items[$name]['state'] = $state;
            $this->items[$name]['error'] = $error;
        }
    }
}
