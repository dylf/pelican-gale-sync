<?php

namespace Dylf\GaleSync\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Short-lived files for zips that are being read: ZipArchive needs a real path, so a download is parked on the panel's
 * local storage disk (storage/app) for as long as it is open. Everything goes through Laravel's Storage layer, so
 * nothing is written outside that disk.
 */
final class Scratch
{
    private const DIRECTORY = 'gale-sync';

    /**
     * A name for a new scratch file on the disk. Nothing is created yet.
     */
    public static function reserve(string $prefix): string
    {
        Storage::disk('local')->makeDirectory(self::DIRECTORY);

        return self::DIRECTORY . '/' . $prefix . '-' . Str::uuid() . '.zip';
    }

    public static function put(string $name, string $contents): void
    {
        Storage::disk('local')->put($name, $contents);
    }

    /**
     * The absolute path of a scratch file, for APIs that need one (ZipArchive, an HTTP download's sink).
     */
    public static function path(string $name): string
    {
        return Storage::disk('local')->path($name);
    }

    public static function delete(string $name): void
    {
        Storage::disk('local')->delete($name);
    }
}
