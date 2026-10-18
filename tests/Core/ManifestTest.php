<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\Manifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    private function r2x(): Manifest
    {
        return Manifest::fromR2x((string) file_get_contents(__DIR__ . '/../fixtures/valheim-export.r2x'));
    }

    private function meta(): Manifest
    {
        $meta = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valheim-meta.json'), true);

        return Manifest::fromMeta($meta['manifest']);
    }

    public function test_it_reads_the_r2x_export_of_a_profile(): void
    {
        $manifest = $this->r2x();

        $this->assertSame('Example Profile', $manifest->profileName);
        $this->assertSame('valheim', $manifest->community);
        $this->assertCount(32, $manifest->mods);

        $mod = $manifest->find('Azumatt-AzuCraftyBoxes');
        $this->assertSame('1.8.28', $mod->version);
        $this->assertSame('Hexium', $mod->source);
        $this->assertSame('Azumatt', $mod->owner());
        $this->assertSame('AzuCraftyBoxes', $mod->name());
        $this->assertSame('Thunderstore', $manifest->find('ValheimModding-Jotunn')->source);
    }

    public function test_it_reads_the_meta_json_of_the_sync_api_which_has_no_sources(): void
    {
        $manifest = $this->meta();

        $this->assertCount(32, $manifest->mods);
        $this->assertNull($manifest->find('Azumatt-AzuCraftyBoxes')->source);
        $this->assertSame('1.116.0', $manifest->find('JereKuusela-Server_devcommands')->version);
    }

    public function test_sources_can_be_copied_from_the_full_export(): void
    {
        $manifest = $this->meta()->withSourcesFrom($this->r2x());

        $this->assertSame('Hexium', $manifest->find('Azumatt-AzuCraftyBoxes')->source);
        $this->assertSame('1.116.0', $manifest->find('JereKuusela-Server_devcommands')->version, 'versions come from the manifest, not the donor');
    }

    public function test_a_mod_name_may_contain_dashes_only_after_the_owner(): void
    {
        $manifest = Manifest::fromMeta(['profileName' => 'p', 'mods' => [['name' => 'LVH-IT-UseEquipmentInWater', 'enabled' => false, 'version' => ['major' => 1, 'minor' => 0, 'patch' => 2]]]]);
        $mod = $manifest->mods[0];

        $this->assertSame('LVH', $mod->owner());
        $this->assertSame('IT-UseEquipmentInWater', $mod->name());
        $this->assertFalse($mod->enabled);
    }

    public function test_a_mod_without_a_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Manifest::fromMeta(['mods' => [['name' => 'A-B']]]);
    }

    public function test_garbage_yaml_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Manifest::fromR2x("a: [unclosed\n");
    }
}
