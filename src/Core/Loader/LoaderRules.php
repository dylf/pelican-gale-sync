<?php

namespace Dylf\GaleSync\Core\Loader;

/**
 * Where the files of a mod package end up for one mod loader. A port of Gale's `SubdirInstaller`, which follows
 * r2modman's rules: https://github.com/ebkr/r2modmanPlus/wiki/Structuring-your-Thunderstore-package
 */
final readonly class LoaderRules
{
    /**
     * @param  list<Subdir>  $subdirs  checked after $extraSubdirs
     * @param  list<Subdir>  $extraSubdirs  per-game additions from games.json
     * @param  int|null  $defaultSubdir  index into $subdirs for files with no marker folder; null ignores them
     * @param  list<string>  $ignoredFiles
     * @param  list<string>  $configDirs  folders that hold mod settings
     */
    public function __construct(
        public string $loader,
        public array $subdirs,
        public ?int $defaultSubdir,
        public array $extraSubdirs = [],
        public array $ignoredFiles = [],
        public array $configDirs = [],
        public ?string $loaderPackage = null,
        public ?string $logPath = null,
    ) {}

    /**
     * The loader's own package (e.g. BepInExPack). Servers get the loader from their egg, so it is never installed.
     */
    public function isLoaderPackage(string $fullName): bool
    {
        if ($this->loaderPackage !== null) {
            return $fullName === $this->loaderPackage;
        }

        return match ($this->loader) {
            'BepInEx' => str_starts_with($fullName, 'BepInEx-BepInExPack') || $fullName === 'silksong_modding-BepInExPack_Silksong',
            default => false,
        };
    }

    /**
     * Where one file of a package goes.
     *
     * @param  string  $relativePath  path inside the package zip, with `/` separators
     * @return string|null path relative to the game directory, or null when the file is not installed
     */
    public function map(string $relativePath, string $packageName): ?string
    {
        if (in_array($relativePath, $this->ignoredFiles, true)) {
            return null;
        }

        $components = self::components($relativePath);

        if ($components === []) {
            return null;
        }

        // Everything up to and including the first marker folder goes in $prev; what follows is $rest.
        $prev = [];
        $rest = [];
        $subdir = null;

        foreach ($components as $index => $component) {
            $prev[] = $component;

            if ($found = $this->matchSubdir($component)) {
                $subdir = $found;
                $rest = array_slice($components, $index + 1);

                break;
            }
        }

        if ($subdir === null) {
            if ($this->defaultSubdir === null) {
                return null;
            }

            $subdir = $this->subdirs[$this->defaultSubdir];
        }

        $target = $subdir->target;

        if ($subdir->mode->separates()) {
            $target .= '/' . $packageName;
        }

        $flatten = $subdir->mode->flattens();

        if ($rest === []) {
            // The file sits directly in the marker folder, or no marker was found at all.
            if ($flatten) {
                $target .= '/' . end($prev);
            } else {
                $target .= '/' . implode('/', $prev);
            }
        } else {
            if (!$flatten) {
                // Keep the folders that came before the marker, but not the marker itself.
                array_pop($prev);

                if ($prev !== []) {
                    $target .= '/' . implode('/', $prev);
                }
            }

            $rest = $this->skipOverlap($rest, $subdir, $flatten);

            if ($rest !== []) {
                $target .= '/' . implode('/', $rest);
            }
        }

        return $target;
    }

    /**
     * The rule that handles files under $path, if any, e.g. for deciding if a file is a setting that must not be replaced.
     */
    public function subdirFor(string $path): ?Subdir
    {
        foreach ([...$this->extraSubdirs, ...$this->subdirs] as $subdir) {
            if ($path === $subdir->target || str_starts_with($path, $subdir->target . '/')) {
                return $subdir;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function components(string $path): array
    {
        $components = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($components);

                continue;
            }

            $components[] = $part;
        }

        return $components;
    }

    private function matchSubdir(string $component): ?Subdir
    {
        foreach ([...$this->extraSubdirs, ...$this->subdirs] as $subdir) {
            if ($subdir->matches($component)) {
                return $subdir;
            }
        }

        return null;
    }

    /**
     * When the target starts with the marker's own name (a "Renderer" marker that targets "Renderer/BepInEx/plugins")
     * and the package already contains the rest of that path, don't repeat it.
     *
     * @param  list<string>  $rest
     * @return list<string>
     */
    private function skipOverlap(array $rest, Subdir $subdir, bool $flatten): array
    {
        if (!$flatten || !str_starts_with($subdir->target, $subdir->name)) {
            return $rest;
        }

        $suffix = ltrim(substr($subdir->target, strlen($subdir->name)), '/');

        if ($suffix === '') {
            return $rest;
        }

        $expected = explode('/', $suffix);

        if (array_slice($rest, 0, count($expected)) === $expected) {
            return array_slice($rest, count($expected));
        }

        return $rest;
    }
}
