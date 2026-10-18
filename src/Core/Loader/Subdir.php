<?php

namespace Dylf\GaleSync\Core\Loader;

/**
 * A folder of the game directory that a package folder named `$name` is installed into (r2modman's install rules).
 */
final readonly class Subdir
{
    public function __construct(
        /** The package folder that triggers this rule. Matched case-insensitively. */
        public string $name,
        /** Where it goes, relative to the game directory. */
        public string $target,
        public SubdirMode $mode = SubdirMode::SeparateFlatten,
        /** Files here are meant to be edited, so an existing copy is never replaced. */
        public bool $mutable = false,
        /** Comma separated file endings that also route to this folder, e.g. ".mm.dll". */
        public ?string $extension = null,
    ) {}

    public function matches(string $component): bool
    {
        if (strcasecmp($this->name, $component) === 0) {
            return true;
        }

        if ($this->extension === null) {
            return false;
        }

        foreach (explode(',', $this->extension) as $ending) {
            if ($ending !== '' && str_ends_with($component, $ending)) {
                return true;
            }
        }

        return false;
    }
}
