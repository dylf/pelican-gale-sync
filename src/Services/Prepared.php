<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\InstallState;
use Dylf\GaleSync\Core\Loader\LoaderRules;
use Dylf\GaleSync\Core\Manifest;
use Dylf\GaleSync\Core\ProfileId;
use Dylf\GaleSync\Core\SyncPlan;

/**
 * Everything worked out for one server before a sync: the profile, the game, what is on the server and what to do.
 */
final readonly class Prepared
{
    /**
     * @param  array<string, mixed>  $game
     * @param  list<string>  $backends
     */
    public function __construct(
        public ProfileId $id,
        public ?string $updatedAt,
        public ProfileArchive $archive,
        public Manifest $manifest,
        public array $game,
        public array $backends,
        public LoaderRules $rules,
        public InstallState $state,
        public SyncPlan $plan,
    ) {}

    public function community(): string
    {
        return (string) $this->manifest->community;
    }
}
