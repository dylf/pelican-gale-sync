<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\ManifestMod;
use Dylf\GaleSync\Core\ModTags;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Where mod packages are downloaded from: Thunderstore and Hexium, which share an API shape.
 */
class PackageSources
{
    public const THUNDERSTORE = 'Thunderstore';

    public const HEXIUM = 'Hexium';

    public function downloadUrl(string $source, ManifestMod $mod): string
    {
        return match ($source) {
            self::HEXIUM => "https://cdn.hexium.gg/uploads/{$mod->owner()}/{$mod->name()}/{$mod->version}.zip",
            default => "https://thunderstore.io/package/download/{$mod->owner()}/{$mod->name()}/{$mod->version}/",
        };
    }

    /**
     * Which site to download a mod from. The profile says so when it can; otherwise the first of the game's sites that
     * has that exact version.
     *
     * @param  list<string>  $backends
     */
    public function resolve(ManifestMod $mod, array $backends): string
    {
        if ($mod->source !== null) {
            return $mod->source;
        }

        foreach ($backends ?: [self::THUNDERSTORE] as $source) {
            try {
                if (Http::timeout(20)->head($this->downloadUrl($source, $mod))->successful()) {
                    return $source;
                }
            } catch (Throwable) {
                // Try the next site.
            }
        }

        throw new GaleSyncException("{$mod->fullName} {$mod->version} was not found on " . implode(' or ', $backends ?: [self::THUNDERSTORE]) . '.');
    }

    /**
     * Which of these mods are tagged client-side only, by `Owner-Name`. Mods the site can't tell us about are left out.
     *
     * @param  list<ManifestMod>  $mods
     * @param  list<string>  $backends  the game's sites; a mod the profile gives no source for is asked about on the first
     * @return array<string, bool>
     */
    public function clientOnly(array $mods, string $community, array $backends): array
    {
        $ttl = now()->addHours((int) config('gale-sync.cache_hours', 12));
        $result = [];
        $missing = [];

        foreach ($mods as $mod) {
            $source = $mod->source ?? ($backends[0] ?? self::THUNDERSTORE);
            $key = "gale-sync:client-only:$source:{$mod->fullName}";

            if (($cached = Cache::get($key)) !== null) {
                $result[$mod->fullName] = (bool) $cached;
            } else {
                $missing[$mod->fullName] = [$mod, $source, $key];
            }
        }

        if ($missing === []) {
            return array_filter($result);
        }

        $responses = Http::pool(function (Pool $pool) use ($missing) {
            foreach ($missing as $name => [$mod, $source]) {
                $host = $source === self::HEXIUM ? 'https://hexium.gg' : 'https://thunderstore.io';
                $pool->as($name)->timeout(20)->get("$host/api/experimental/package/{$mod->owner()}/{$mod->name()}/");
            }
        });

        foreach ($missing as $name => [, , $key]) {
            $response = $responses[$name] ?? null;

            if (!$response instanceof Response || !$response->successful()) {
                continue;
            }

            foreach ((array) $response->json('community_listings') as $listing) {
                if (($listing['community'] ?? null) === $community) {
                    $clientOnly = ModTags::isClientOnly((array) ($listing['categories'] ?? []));
                    Cache::put($key, $clientOnly, $ttl);
                    $result[$name] = $clientOnly;

                    break;
                }
            }
        }

        return array_filter($result);
    }
}
