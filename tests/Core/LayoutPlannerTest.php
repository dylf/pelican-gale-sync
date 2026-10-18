<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\LayoutPlanner;
use Dylf\GaleSync\Core\Loader\LoaderRulesFactory;
use PHPUnit\Framework\TestCase;

class LayoutPlannerTest extends TestCase
{
    public function test_a_bepinex_package_becomes_one_folder_plus_untouched_settings(): void
    {
        $rules = LoaderRulesFactory::fromGame(['name' => 'BepInEx']);

        $layout = (new LayoutPlanner())->plan($rules, 'Azumatt-Mod', [
            'Mod.dll', 'manifest.json', 'icon.png', 'config/Azumatt.Mod.cfg', 'patchers/P.dll',
        ]);

        $this->assertEqualsCanonicalizing(['BepInEx/plugins/Azumatt-Mod', 'BepInEx/patchers/Azumatt-Mod'], $layout->dirs);
        $this->assertSame([], $layout->files);

        $byFrom = array_column($layout->moves, null, 'from');
        $this->assertSame('BepInEx/plugins/Azumatt-Mod/Mod.dll', $byFrom['Mod.dll']['to']);
        $this->assertFalse($byFrom['Mod.dll']['config']);
        $this->assertSame('BepInEx/config/Azumatt.Mod.cfg', $byFrom['config/Azumatt.Mod.cfg']['to']);
        $this->assertTrue($byFrom['config/Azumatt.Mod.cfg']['config'], 'settings must never replace an existing file');
    }

    public function test_melonloader_files_are_tracked_one_by_one(): void
    {
        $rules = LoaderRulesFactory::fromGame(['name' => 'MelonLoader']);

        $layout = (new LayoutPlanner())->plan($rules, 'Owner-Cool', ['Cool.dll', 'manifest.json', 'README.md']);

        $this->assertSame([], $layout->dirs);
        $this->assertSame(['Mods/Cool.dll'], $layout->files);
        $this->assertSame(['manifest.json', 'README.md'], $layout->ignored);
    }
}
