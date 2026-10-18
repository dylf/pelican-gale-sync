<?php

namespace Dylf\GaleSync\Core;

use JsonException;

/**
 * The record kept on the server (`.gale-sync.json`) of what was installed from which profile. Only what is listed here
 * is ever replaced or removed; files an admin uploaded by hand are left alone.
 */
final class InstallState
{
    public const FILE = '.gale-sync.json';

    /**
     * @param  array<string, InstalledMod>  $mods  by `Owner-Name`
     */
    public function __construct(
        public array $mods = [],
        public ?string $profileId = null,
        public ?string $profileName = null,
        public ?string $profileUpdatedAt = null,
        public ?string $syncedAt = null,
    ) {}

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self();
        }

        $mods = [];

        foreach (is_array($data) ? ($data['mods'] ?? []) : [] as $name => $mod) {
            $mods[(string) $name] = InstalledMod::fromArray($mod);
        }

        return new self(
            $mods,
            $data['profile']['id'] ?? null,
            $data['profile']['name'] ?? null,
            $data['profile']['updated_at'] ?? null,
            $data['synced_at'] ?? null,
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'version' => 1,
            'profile' => ['id' => $this->profileId, 'name' => $this->profileName, 'updated_at' => $this->profileUpdatedAt],
            'synced_at' => $this->syncedAt,
            'mods' => array_map(fn (InstalledMod $mod) => $mod->toArray(), $this->mods),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
