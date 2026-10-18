<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\Loader\LoaderRules;
use Dylf\GaleSync\Core\Loader\LoaderRulesFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gale's list of games: which mod loader each uses and which sites host its mods.
 */
class GamesRegistry
{
    /**
     * @return array<string, mixed>|null the games.json entry for a Thunderstore community slug, e.g. "valheim"
     */
    public function find(string $community): ?array
    {
        foreach ($this->games() as $game) {
            if (self::slug($game) === $community) {
                return $game;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $game
     */
    public function rules(array $game): ?LoaderRules
    {
        return LoaderRulesFactory::fromGame((array) ($game['modLoader'] ?? []));
    }

    /**
     * Where a game's mods are hosted. Gale only lists them for games that are on more than Thunderstore.
     *
     * @param  array<string, mixed>  $game
     * @return list<string> "Thunderstore" and/or "Hexium"
     */
    public function backends(array $game): array
    {
        return array_values($game['backends'] ?? ['Thunderstore']);
    }

    /**
     * @param  array<string, mixed>  $game
     */
    public static function slug(array $game): string
    {
        return (string) ($game['slug'] ?? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($game['name'] ?? ''))), '-'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function games(): array
    {
        return Cache::remember('gale-sync:games', now()->addHours((int) config('gale-sync.cache_hours', 12)), function () {
            try {
                $games = Http::timeout(30)->get((string) config('gale-sync.games_url'))->throw()->json();
            } catch (Throwable $exception) {
                throw new GaleSyncException('Could not load Gale\'s games list: ' . $exception->getMessage(), 0, $exception);
            }

            return is_array($games) ? $games : [];
        });
    }
}
