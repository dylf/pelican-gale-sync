<?php

namespace Dylf\GaleSync\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The Pelican hub rejects plugins that use PHP's raw file and process functions. Files go through Laravel's Storage
 * layer instead (see Services/Scratch). This keeps that from creeping back in.
 */
class NoRawFileOperationsTest extends TestCase
{
    private const BANNED = [
        'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'fputs', 'fclose', 'fread', 'fgets', 'readfile',
        'unlink', 'tempnam', 'tmpfile', 'sys_get_temp_dir', 'mkdir', 'rmdir', 'rename', 'copy', 'touch', 'chmod',
        'chown', 'symlink', 'glob', 'scandir', 'opendir',
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'eval',
    ];

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (['src', 'config', 'database', 'routes', 'lang'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_plugin_uses_no_raw_file_or_process_functions(): void
    {
        $found = [];

        foreach ($this->phpFiles() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), self::BANNED, true)) {
                    continue;
                }

                // Only a plain function call counts: not a method call, a static call, a method declaration or a constructor.
                $previous = $this->significant($tokens, $i, -1);
                $next = $this->significant($tokens, $i, 1);

                if ($next !== '(' || in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                    continue;
                }

                $found[] = substr($file, strlen(dirname(__DIR__)) + 1) . ':' . $token[2] . ' ' . $token[1] . '()';
            }
        }

        $this->assertSame([], $found, "Raw file/process functions are rejected by the Pelican hub. Use Laravel's Storage layer instead:\n" . implode("\n", $found));
    }

    /**
     * The next (or previous) token that is not whitespace or a comment: its id for arrays, the character for single chars.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function significant(array $tokens, int $from, int $step): int|string|null
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[0] : $token;
        }

        return null;
    }
}
