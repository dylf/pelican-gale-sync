<?php

namespace Dylf\GaleSync\Core;

/**
 * One mod of a profile: `Owner-Name` at an exact version.
 */
final readonly class ManifestMod
{
    public function __construct(
        public string $fullName,
        public string $version,
        public bool $enabled = true,
        /** "Thunderstore" or "Hexium" when the profile says where the mod came from. */
        public ?string $source = null,
    ) {}

    public function owner(): string
    {
        return explode('-', $this->fullName, 2)[0];
    }

    public function name(): string
    {
        return explode('-', $this->fullName, 2)[1] ?? '';
    }

    public function withSource(?string $source): self
    {
        return new self($this->fullName, $this->version, $this->enabled, $source);
    }
}
