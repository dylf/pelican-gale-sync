<?php

namespace Dylf\GaleSync\Core;

/**
 * What the admin pasted into the "profile" field.
 *
 * - A Gale sync ID (6 alphanumeric characters, or a 22 character URL-safe base64 UUID) follows a profile that its
 *   owner keeps updating from Gale.
 * - A UUID is a profile code from "Export profile as code": a frozen snapshot that never changes.
 */
final readonly class ProfileId
{
    public const SYNC = 'sync';

    public const CODE = 'code';

    private function __construct(public string $kind, public string $value) {}

    public static function parse(string $input): ?self
    {
        $input = trim($input);

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return new self(self::CODE, strtolower($input));
        }

        if (preg_match('/^[A-Za-z0-9]{6}$/', $input) || preg_match('/^[A-Za-z0-9_-]{22}$/', $input)) {
            return new self(self::SYNC, $input);
        }

        return null;
    }

    public function isSync(): bool
    {
        return $this->kind === self::SYNC;
    }
}
