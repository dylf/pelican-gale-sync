<?php

namespace Dylf\GaleSync\Core;

/**
 * Where the files of one extracted package go.
 */
final readonly class Layout
{
    /**
     * @param  list<array{from: string, to: string, config: bool}>  $moves  `config` files are never replaced if present
     * @param  list<string>  $dirs  per-mod folders the moves create
     * @param  list<string>  $files  moved files that are tracked one by one (no per-mod folder)
     * @param  list<string>  $ignored  package files that are not installed
     */
    public function __construct(
        public array $moves,
        public array $dirs,
        public array $files,
        public array $ignored,
    ) {}
}
