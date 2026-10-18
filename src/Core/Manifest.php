<?php

namespace Dylf\GaleSync\Core;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A Gale profile's mod list.
 *
 * Gale's sync API returns it as JSON (`/profile/{id}/meta`, no sources) and inside the profile zip as r2modman's
 * `export.r2x` YAML (which also records where each mod came from).
 */
final readonly class Manifest
{
    /**
     * @param  list<ManifestMod>  $mods
     */
    public function __construct(
        public string $profileName,
        public ?string $community,
        public array $mods,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the `manifest` object of a `/profile/{id}/meta` response
     */
    public static function fromMeta(array $data): self
    {
        return self::fromArray($data);
    }

    public static function fromR2x(string $yaml): self
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException('export.r2x is not valid YAML: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('export.r2x is empty');
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromArray(array $data): self
    {
        $mods = [];

        foreach ($data['mods'] ?? [] as $mod) {
            $version = $mod['version'] ?? null;

            if (!isset($mod['name']) || !is_array($version)) {
                throw new InvalidArgumentException('Profile has a mod without a name or version');
            }

            $mods[] = new ManifestMod(
                (string) $mod['name'],
                sprintf('%d.%d.%d', $version['major'] ?? 0, $version['minor'] ?? 0, $version['patch'] ?? 0),
                (bool) ($mod['enabled'] ?? true),
                isset($mod['source']) ? (string) $mod['source'] : null,
            );
        }

        return new self((string) ($data['profileName'] ?? ''), isset($data['community']) ? (string) $data['community'] : null, $mods);
    }

    public function find(string $fullName): ?ManifestMod
    {
        foreach ($this->mods as $mod) {
            if ($mod->fullName === $fullName) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * Copy the download sources from another manifest of the same profile (the /meta one has none).
     */
    public function withSourcesFrom(self $other): self
    {
        return new self($this->profileName, $this->community ?? $other->community, array_map(
            fn (ManifestMod $mod) => $mod->source === null ? $mod->withSource($other->find($mod->fullName)?->source) : $mod,
            $this->mods,
        ));
    }
}
