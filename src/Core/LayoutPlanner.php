<?php

namespace Dylf\GaleSync\Core;

use Dylf\GaleSync\Core\Loader\LoaderRules;
use Dylf\GaleSync\Core\Loader\SubdirMode;

final class LayoutPlanner
{
    /**
     * @param  list<string>  $packageFiles  every file of the extracted package, relative to its root
     */
    public function plan(LoaderRules $rules, string $fullName, array $packageFiles): Layout
    {
        $moves = [];
        $dirs = [];
        $files = [];
        $ignored = [];

        foreach ($packageFiles as $file) {
            $to = $rules->map($file, $fullName);

            if ($to === null) {
                $ignored[] = $file;

                continue;
            }

            $subdir = $rules->subdirFor($to);
            $config = $subdir !== null && $subdir->mode === SubdirMode::None && $subdir->mutable;

            $moves[] = ['from' => $file, 'to' => $to, 'config' => $config];

            if ($subdir?->mode->separates()) {
                $dirs[$subdir->target . '/' . $fullName] = true;
            } elseif ($subdir?->mode === SubdirMode::Track) {
                $files[] = $to;
            }
        }

        return new Layout($moves, array_keys($dirs), $files, $ignored);
    }
}
