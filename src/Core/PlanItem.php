<?php

namespace Dylf\GaleSync\Core;

final readonly class PlanItem
{
    public function __construct(
        public string $fullName,
        public PlanAction $action,
        /** The version on the server now. */
        public ?string $from = null,
        /** The version the profile wants. */
        public ?string $to = null,
        public ?string $source = null,
        /** Why a mod is skipped or removed, when it is not simply "the profile says so". */
        public ?string $reason = null,
    ) {}
}
