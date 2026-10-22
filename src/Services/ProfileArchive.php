<?php

namespace Dylf\GaleSync\Services;

use Dylf\GaleSync\Core\Manifest;
use InvalidArgumentException;
use ZipArchive;

/**
 * A profile zip as Gale exports it: `export.r2x` (the mod list) plus the profile's config files.
 */
class ProfileArchive
{
    private ZipArchive $zip;

    private string $name;

    public function __construct(string $bytes)
    {
        // Profile codes wrap the zip in base64 after a "#r2modman" line; sync downloads are the raw zip.
        if (str_starts_with($bytes, '#r2modman')) {
            $bytes = (string) base64_decode(trim(substr($bytes, (int) strpos($bytes, "\n"))), true);
        }

        $this->name = Scratch::reserve('profile');
        Scratch::put($this->name, $bytes);

        $this->zip = new ZipArchive();

        if ($this->zip->open(Scratch::path($this->name)) !== true) {
            Scratch::delete($this->name);

            throw new GaleSyncException('The profile download is not a valid zip file.');
        }
    }

    public function __destruct()
    {
        $this->zip->close();
        Scratch::delete($this->name);
    }

    public function manifest(): Manifest
    {
        $yaml = $this->zip->getFromName('export.r2x');

        if ($yaml === false) {
            throw new GaleSyncException('The profile has no export.r2x, so its mod list can not be read.');
        }

        try {
            return Manifest::fromR2x($yaml);
        } catch (InvalidArgumentException $exception) {
            throw new GaleSyncException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Config files in the profile that belong in one of the loader's config folders.
     *
     * @param  list<string>  $configDirs  e.g. BepInEx/config
     * @param  list<string>  $ignore  fnmatch() patterns matched against the file name
     * @return list<string> zip entry names
     */
    public function configFiles(array $configDirs, array $ignore): array
    {
        $found = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);
            $normal = str_replace('\\', '/', $name);

            if (str_ends_with($normal, '/')) {
                continue;
            }

            foreach ($configDirs as $dir) {
                if (!str_starts_with($normal, $dir . '/')) {
                    continue;
                }

                foreach ($ignore as $pattern) {
                    if (fnmatch($pattern, basename($normal))) {
                        continue 2;
                    }
                }

                $found[] = $name;
            }
        }

        return $found;
    }

    public function read(string $entry): string
    {
        $content = $this->zip->getFromName($entry);

        if ($content === false) {
            throw new GaleSyncException("'$entry' is missing from the profile zip.");
        }

        return $content;
    }
}
