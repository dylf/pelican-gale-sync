<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\Manifest;
use Dylf\GaleSync\Core\ProfileId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Talks to Gale's sync API, and to the profile-code endpoints of Hexium and Thunderstore.
 */
class GaleClient
{
    private function http(): PendingRequest
    {
        return Http::timeout(60)->retry(2, 500, throw: false)->withUserAgent('gale-sync-pelican-plugin');
    }

    /**
     * The cheap look at a sync ID: when it last changed and what it lists. Mod sources are not in here.
     *
     * @return array{updated_at: string, manifest: Manifest}|null null for a profile code, which never changes
     */
    public function meta(ProfileId $id): ?array
    {
        if (!$id->isSync()) {
            return null;
        }

        try {
            $response = $this->http()->get(rtrim((string) config('gale-sync.api_url'), '/') . '/profile/' . $id->value . '/meta');
        } catch (ConnectionException $exception) {
            throw new GaleSyncException('Could not reach Gale: ' . $exception->getMessage(), 0, $exception);
        }

        if ($response->status() === 404) {
            throw new GaleSyncException("Gale has no synced profile '{$id->value}'. Check the sync ID.");
        }

        if ($response->failed()) {
            throw new GaleSyncException('Gale answered ' . $response->status() . ': ' . ($response->json('message') ?? 'unknown error'));
        }

        try {
            return [
                'updated_at' => (string) $response->json('updatedAt'),
                'manifest' => Manifest::fromMeta((array) $response->json('manifest')),
            ];
        } catch (InvalidArgumentException $exception) {
            throw new GaleSyncException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * The profile zip: mod list with sources, and config files.
     */
    public function archive(ProfileId $id): ProfileArchive
    {
        if ($id->isSync()) {
            return new ProfileArchive($this->download(rtrim((string) config('gale-sync.api_url'), '/') . '/profile/' . $id->value, "synced profile '{$id->value}'"));
        }

        // A profile code lives on Hexium when it has Hexium mods and on Thunderstore otherwise; try both.
        foreach (['https://hexium.gg', 'https://thunderstore.io'] as $host) {
            $bytes = $this->download("$host/api/experimental/legacyprofile/get/{$id->value}/", 'profile code', required: false);

            if ($bytes !== null) {
                return new ProfileArchive($bytes);
            }
        }

        throw new GaleSyncException("No profile with code '{$id->value}' was found on Hexium or Thunderstore. Codes can expire; export a new one or use a Gale sync ID.");
    }

    private function download(string $url, string $what, bool $required = true): ?string
    {
        try {
            $response = $this->http()->get($url);
        } catch (ConnectionException $exception) {
            throw new GaleSyncException("Could not download the $what: " . $exception->getMessage(), 0, $exception);
        } catch (RequestException $exception) {
            throw new GaleSyncException("Could not download the $what: HTTP " . $exception->response->status(), 0, $exception);
        }

        if ($response->successful()) {
            return $response->body();
        }

        if (!$required && $response->status() === 404) {
            return null;
        }

        throw new GaleSyncException("Could not download the $what: HTTP {$response->status()}");
    }
}
