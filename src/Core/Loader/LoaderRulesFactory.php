<?php

namespace Dylf\GaleSync\Core\Loader;

/**
 * Builds the install rules for a game from its `modLoader` entry in Gale's games.json.
 *
 * Only loaders whose rules are a plain list of folders are supported. The others (Northstar, GDWeave, Shimloader, ...)
 * install in loader-specific ways and are reported as unsupported instead of being guessed at.
 */
final class LoaderRulesFactory
{
    /**
     * @param  array<string, mixed>  $modLoader  e.g. {"name": "BepInEx", "packageName": "denikson-BepInExPack_Valheim", "subdirs": [...]}
     */
    public static function fromGame(array $modLoader): ?LoaderRules
    {
        $name = (string) ($modLoader['name'] ?? '');
        $package = isset($modLoader['packageName']) ? (string) $modLoader['packageName'] : null;
        $extra = array_map(fn (array $subdir) => self::subdir($subdir), $modLoader['subdirs'] ?? []);

        return match ($name) {
            'BepInEx' => new LoaderRules(
                'BepInEx',
                [
                    new Subdir('plugins', 'BepInEx/plugins'),
                    new Subdir('patchers', 'BepInEx/patchers'),
                    new Subdir('monomod', 'BepInEx/monomod', extension: '.mm.dll'),
                    new Subdir('core', 'BepInEx/core'),
                    new Subdir('config', 'BepInEx/config', SubdirMode::None, mutable: true),
                ],
                defaultSubdir: 0,
                extraSubdirs: $extra,
                configDirs: ['BepInEx/config'],
                loaderPackage: $package,
                logPath: 'BepInEx/LogOutput.log',
            ),
            'BepisLoader' => new LoaderRules(
                'BepisLoader',
                [
                    new Subdir('Renderer', 'Renderer/BepInEx/plugins'),
                    new Subdir('plugins', 'BepInEx/plugins'),
                    new Subdir('patchers', 'BepInEx/patchers'),
                    new Subdir('monomod', 'BepInEx/monomod', extension: '.mm.dll'),
                    new Subdir('core', 'BepInEx/core'),
                    new Subdir('config', 'BepInEx/config', SubdirMode::None, mutable: true),
                ],
                defaultSubdir: 1,
                extraSubdirs: $extra,
                configDirs: ['BepInEx/config', 'Renderer/BepInEx/config'],
                loaderPackage: $package,
                logPath: 'BepInEx/LogOutput.log',
            ),
            'MelonLoader' => new LoaderRules(
                'MelonLoader',
                [
                    new Subdir('UserLibs', 'UserLibs', SubdirMode::Track, extension: '.lib.dll'),
                    new Subdir('Managed', 'MelonLoader/Managed', SubdirMode::Track, extension: '.managed.dll'),
                    new Subdir('Mods', 'Mods', SubdirMode::Track, extension: '.dll'),
                    new Subdir('Plugins', 'Plugins', SubdirMode::Track),
                    new Subdir('ModManager', 'UserData/ModManager', SubdirMode::Separate),
                    new Subdir('MelonLoader', 'MelonLoader', SubdirMode::Track),
                    new Subdir('Libs', 'MelonLoader/Libs', SubdirMode::Track),
                ],
                defaultSubdir: 2,
                extraSubdirs: $extra,
                ignoredFiles: ['manifest.json', 'icon.png', 'README.md'],
                configDirs: [],
                loaderPackage: $package ?? 'LavaGang-MelonLoader',
                logPath: 'MelonLoader/Latest.log',
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function subdir(array $data): Subdir
    {
        return new Subdir(
            (string) $data['name'],
            (string) $data['target'],
            match ($data['mode'] ?? 'separateFlatten') {
                'separate' => SubdirMode::Separate,
                'track' => SubdirMode::Track,
                'none' => SubdirMode::None,
                default => SubdirMode::SeparateFlatten,
            },
            (bool) ($data['mutable'] ?? false),
            isset($data['extension']) ? (string) $data['extension'] : null,
        );
    }
}
