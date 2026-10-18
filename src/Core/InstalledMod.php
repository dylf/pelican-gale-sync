<?php

namespace Dylf\GaleSync\Core;

/**
 * What this plugin put on the server for one mod, so it can be replaced or removed without touching anything else.
 */
final readonly class InstalledMod
{
    /**
     * @param  list<string>  $dirs  per-mod folders (BepInEx/plugins/Owner-Name), removed as a whole
     * @param  list<string>  $files  individually tracked files, for loaders that don't give every mod a folder
     */
    public function __construct(
        public string $version,
        public ?string $source,
        public array $dirs = [],
        public array $files = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['version'] ?? ''),
            isset($data['source']) ? (string) $data['source'] : null,
            array_values(array_map('strval', $data['dirs'] ?? [])),
            array_values(array_map('strval', $data['files'] ?? [])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['version' => $this->version, 'source' => $this->source, 'dirs' => $this->dirs, 'files' => $this->files];
    }
}
