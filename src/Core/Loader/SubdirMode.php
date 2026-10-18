<?php

namespace Dylf\GaleSync\Core\Loader;

enum SubdirMode
{
    /** Separate mods into `Owner-Name` folders and keep the folders that come before the marker. */
    case Separate;

    /** Same, but flatten whatever comes before the marker. Gale's default. */
    case SeparateFlatten;

    /** No per-mod folder; the files are tracked individually. */
    case Track;

    /** No per-mod folder and no tracking, e.g. BepInEx/config. */
    case None;

    public function separates(): bool
    {
        return $this === self::Separate || $this === self::SeparateFlatten;
    }

    public function flattens(): bool
    {
        return $this !== self::Separate;
    }
}
