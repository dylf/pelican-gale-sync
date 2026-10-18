<?php

namespace Dylf\GaleSync\Core;

final class ModTags
{
    /**
     * A mod is client-side only when it is tagged for clients and not for servers. Thunderstore tags it "Client-side" or
     * "Server-side"; Hexium has "Client (& Server)". Untagged mods are assumed to be needed on the server.
     *
     * @param  list<string>  $categories
     */
    public static function isClientOnly(array $categories): bool
    {
        $client = false;

        foreach ($categories as $category) {
            $category = strtolower($category);

            if (str_contains($category, 'server')) {
                return false;
            }

            if (str_contains($category, 'client')) {
                $client = true;
            }
        }

        return $client;
    }
}
