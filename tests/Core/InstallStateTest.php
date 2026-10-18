<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\InstalledMod;
use Dylf\GaleSync\Core\InstallState;
use PHPUnit\Framework\TestCase;

class InstallStateTest extends TestCase
{
    public function test_it_survives_a_round_trip(): void
    {
        $state = new InstallState(
            ['A-One' => new InstalledMod('1.2.3', 'Hexium', ['BepInEx/plugins/A-One'], ['Mods/x.dll'])],
            'Ab12Cd', 'Example Profile', '2026-10-21T19:58:43Z', '2026-10-21T20:00:00Z',
        );

        $copy = InstallState::fromJson($state->toJson());

        $this->assertEquals($state, $copy);
    }

    public function test_a_missing_or_broken_file_is_an_empty_state(): void
    {
        $this->assertSame([], InstallState::fromJson(null)->mods);
        $this->assertSame([], InstallState::fromJson('')->mods);
        $this->assertSame([], InstallState::fromJson('{nope')->mods);
        $this->assertSame([], InstallState::fromJson('"a string"')->mods);
    }
}
