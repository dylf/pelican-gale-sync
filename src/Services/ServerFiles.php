<?php

namespace Dylf\GaleSync\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The few file operations a sync needs, on one server, through Wings.
 */
class ServerFiles
{
    /** @var array<string, list<string>> directory listings, remembered for the length of one sync */
    private array $listings = [];

    public function __construct(private readonly DaemonFileRepository $files, Server $server)
    {
        $this->files->setServer($server);
    }

    public function read(string $path): ?string
    {
        try {
            return $this->files->getContent('/' . ltrim($path, '/'));
        } catch (FileNotFoundException) {
            return null;
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function write(string $path, string $content): void
    {
        $this->files->putContent('/' . ltrim($path, '/'), $content)->throw();

        // Remember the new file instead of listing the folder again for every file of a package.
        $parent = dirname(trim($path, '/'));
        $directory = '/' . ($parent === '.' ? '' : $parent);

        if (isset($this->listings[$directory]) && !in_array(basename($path), $this->listings[$directory], true)) {
            $this->listings[$directory][] = basename($path);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public function delete(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $this->files->deleteFiles('/', array_map(fn (string $path) => ltrim($path, '/'), $paths))->throw();

        $this->listings = [];
    }

    /**
     * Deletes the paths that exist and ignores the ones that don't.
     *
     * @param  list<string>  $paths
     */
    public function deleteExisting(array $paths): void
    {
        $this->delete(array_values(array_filter($paths, fn (string $path) => $this->exists($path))));
    }

    /**
     * The names in a directory; empty when it doesn't exist.
     *
     * @return list<string>
     */
    public function names(string $directory): array
    {
        $directory = '/' . trim($directory, '/');

        if (isset($this->listings[$directory])) {
            return $this->listings[$directory];
        }

        try {
            $entries = $this->files->getDirectory($directory);
        } catch (Throwable) {
            return $this->listings[$directory] = [];
        }

        return $this->listings[$directory] = array_values(array_filter(array_map(fn ($entry) => is_array($entry) ? ($entry['name'] ?? null) : null, $entries)));
    }

    public function exists(string $path): bool
    {
        $path = '/' . trim($path, '/');

        return in_array(basename($path), $this->names(dirname($path)), true);
    }
}
