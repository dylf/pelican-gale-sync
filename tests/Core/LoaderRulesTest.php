<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\Loader\LoaderRules;
use Dylf\GaleSync\Core\Loader\LoaderRulesFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoaderRulesTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function loaders(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/mod-loaders.json'), true);
    }

    private static function valheim(): LoaderRules
    {
        return LoaderRulesFactory::fromGame(self::loaders()['Valheim']);
    }

    /** @return array<string, array{string, ?string}> */
    public static function bepinex(): array
    {
        $dir = 'BepInEx/plugins/Azumatt-Mod';

        return [
            'a dll in plugins' => ['plugins/Mod.dll', "$dir/Mod.dll"],
            'a dll with no folder goes to plugins' => ['Mod.dll', "$dir/Mod.dll"],
            'folders before the marker are dropped' => ['Wrapper/plugins/Mod.dll', "$dir/Mod.dll"],
            'folders after the marker are kept' => ['plugins/Translations/de.json', "$dir/Translations/de.json"],
            'a folder with no marker is flattened' => ['Wrapper/Mod.dll', "$dir/Mod.dll"],
            'marker names are case-insensitive' => ['Plugins/Mod.dll', "$dir/Mod.dll"],
            'the package manifest is installed too' => ['manifest.json', "$dir/manifest.json"],
            'patchers' => ['patchers/Patch.dll', 'BepInEx/patchers/Azumatt-Mod/Patch.dll'],
            'core' => ['core/Lib.dll', 'BepInEx/core/Azumatt-Mod/Lib.dll'],
            'monomod by extension' => ['Hook.mm.dll', 'BepInEx/monomod/Azumatt-Mod/Hook.mm.dll'],
            'settings are not put in a mod folder' => ['config/mod.cfg', 'BepInEx/config/mod.cfg'],
            'settings keep their own folders' => ['config/Mod/sub/a.yml', 'BepInEx/config/Mod/sub/a.yml'],
            'a game specific folder (Valheim SlimVML)' => ['SlimVML/Thing.dll', 'BepInEx/SlimVML/Azumatt-Mod/Thing.dll'],
            'parent folders cannot escape' => ['../../plugins/Mod.dll', "$dir/Mod.dll"],
            'the empty path is ignored' => ['', null],
        ];
    }

    #[DataProvider('bepinex')]
    public function test_bepinex_maps_package_files(string $file, ?string $expected): void
    {
        $this->assertSame($expected, self::valheim()->map($file, 'Azumatt-Mod'));
    }

    public function test_the_bepinex_package_is_the_loader_and_is_never_a_mod(): void
    {
        $rules = self::valheim();

        $this->assertTrue($rules->isLoaderPackage('denikson-BepInExPack_Valheim'));
        $this->assertFalse($rules->isLoaderPackage('ValheimModding-Jotunn'));
    }

    public function test_without_a_package_name_the_standard_bepinex_pack_prefix_is_used(): void
    {
        $rules = LoaderRulesFactory::fromGame(['name' => 'BepInEx']);

        $this->assertTrue($rules->isLoaderPackage('BepInEx-BepInExPack_Something'));
    }

    public function test_melonloader_tracks_files_and_ignores_package_metadata(): void
    {
        $rules = LoaderRulesFactory::fromGame(self::loaders()['BONEWORKS']);

        $this->assertSame('Mods/Cool.dll', $rules->map('Cool.dll', 'Owner-Cool'), 'a dll with no folder is a mod');
        $this->assertSame('Mods/Cool.dll', $rules->map('Mods/Cool.dll', 'Owner-Cool'));
        $this->assertSame('UserLibs/Lib.lib.dll', $rules->map('Lib.lib.dll', 'Owner-Cool'));
        $this->assertSame('UserData/ModManager/Owner-Cool/x.json', $rules->map('ModManager/x.json', 'Owner-Cool'));
        $this->assertNull($rules->map('manifest.json', 'Owner-Cool'));
        $this->assertNull($rules->map('icon.png', 'Owner-Cool'));
        $this->assertTrue($rules->isLoaderPackage('LavaGang-MelonLoader'));
    }

    public function test_bepisloader_does_not_repeat_the_renderer_path(): void
    {
        $rules = LoaderRulesFactory::fromGame(self::loaders()['Resonite']);

        $this->assertSame('Renderer/BepInEx/plugins/Owner-Mod/R.dll', $rules->map('Renderer/BepInEx/plugins/R.dll', 'Owner-Mod'));
        $this->assertSame('Renderer/BepInEx/plugins/Owner-Mod/R.dll', $rules->map('Renderer/R.dll', 'Owner-Mod'));
        $this->assertSame('BepInEx/plugins/Owner-Mod/M.dll', $rules->map('M.dll', 'Owner-Mod'));
    }

    public function test_loaders_that_install_in_their_own_way_are_not_supported(): void
    {
        $this->assertNull(LoaderRulesFactory::fromGame(self::loaders()['Titanfall 2: Northstar']));
        $this->assertNull(LoaderRulesFactory::fromGame(self::loaders()['WEBFISHING']));
    }
}
