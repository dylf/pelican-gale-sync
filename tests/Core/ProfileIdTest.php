<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\ProfileId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileIdTest extends TestCase
{
    public function test_a_six_character_code_is_a_sync_id(): void
    {
        $id = ProfileId::parse(' Ab12Cd ');

        $this->assertTrue($id->isSync());
        $this->assertSame('Ab12Cd', $id->value);
    }

    public function test_a_base64_uuid_is_a_sync_id(): void
    {
        $id = ProfileId::parse('GsioqKpVRwiP7_ynX-QsuA');

        $this->assertTrue($id->isSync());
        $this->assertSame('GsioqKpVRwiP7_ynX-QsuA', $id->value);
    }

    public function test_a_uuid_is_a_profile_code_snapshot(): void
    {
        $id = ProfileId::parse('0B8F6C1E-3a52-4d7e-9c41-6f2a8d5e1b37');

        $this->assertFalse($id->isSync());
        $this->assertSame(ProfileId::CODE, $id->kind);
        $this->assertSame('0b8f6c1e-3a52-4d7e-9c41-6f2a8d5e1b37', $id->value);
    }

    #[DataProvider('invalid')]
    public function test_anything_else_is_rejected(string $input): void
    {
        $this->assertNull(ProfileId::parse($input));
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return [
            'empty' => [''],
            'too short' => ['ABC12'],
            'seven characters' => ['ABC1234'],
            'six with a symbol' => ['ABC-12'],
            'a url' => ['https://gale.kesomannen.com/api/profile/Ab12Cd'],
            'uuid with a typo' => ['0b8f6c1e-3a52-4d7e-9c41-6f2a8d5e1b3z'],
        ];
    }
}
