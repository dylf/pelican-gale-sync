<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\ModTags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModTagsTest extends TestCase
{
    /** @return array<string, array{list<string>, bool}> */
    public static function cases(): array
    {
        return [
            'thunderstore client-side' => [['Mods', 'Client-side', 'Deep North Update'], true],
            'thunderstore server-side' => [['Mods', 'Server-side'], false],
            'thunderstore both' => [['Client-side', 'Server-side'], false],
            'hexium client and server' => [['Mechanics', 'Open Source', 'Client (& Server)'], false],
            'untagged' => [['Libraries', 'Deep North Update'], false],
            'no categories' => [[], false],
        ];
    }

    /** @param list<string> $categories */
    #[DataProvider('cases')]
    public function test_only_client_tagged_mods_are_client_only(array $categories, bool $expected): void
    {
        $this->assertSame($expected, ModTags::isClientOnly($categories));
    }
}
