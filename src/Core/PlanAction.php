<?php

namespace Dylf\GaleSync\Core;

enum PlanAction: string
{
    case Install = 'install';
    case Update = 'update';
    case Remove = 'remove';
    case Keep = 'keep';
    case Skip = 'skip';

    public function changes(): bool
    {
        return in_array($this, [self::Install, self::Update, self::Remove], true);
    }
}
