<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Patch;

/**
 * Minimal unified-diff parser. Extracts, per target file, the added and
 * removed lines so PatchVerifier can check whether the patch is present in
 * the live tree. Deliberately tolerant: metadata lines (index, mode, rename,
 * binary) are skipped, and +/- lines are only honoured inside hunks.
 */
class UnifiedDiffParser
{
    /**
     * @return array<string, array{added: string[], removed: string[]}> keyed by target path
     */
    public function parse(string $diff): array
    {
        $files = [];
        $current = null;
        $pendingOld = null;
        $inHunk = false;

        foreach (preg_split('/\r\n|\r|\n/', $diff) as $line) {
            if (str_starts_with($line, 'diff ')) {
                $inHunk = false;
                $pendingOld = null;
                continue;
            }
            if (str_starts_with($line, '--- ')) {
                $pendingOld = $this->normalizePath(substr($line, 4));
                $inHunk = false;
                continue;
            }
            if (str_starts_with($line, '+++ ')) {
                // Deleted files have "+++ /dev/null"; fall back to the old path.
                $current = $this->normalizePath(substr($line, 4)) ?? $pendingOld;
                if ($current !== null && !isset($files[$current])) {
                    $files[$current] = ['added' => [], 'removed' => []];
                }
                $inHunk = false;
                continue;
            }
            if (str_starts_with($line, '@@')) {
                $inHunk = $current !== null;
                continue;
            }
            if (!$inHunk) {
                continue;
            }
            $first = $line === '' ? ' ' : $line[0];
            if ($first === '+') {
                $files[$current]['added'][] = substr($line, 1);
            } elseif ($first === '-') {
                $files[$current]['removed'][] = substr($line, 1);
            } elseif ($first !== ' ' && $first !== '\\') {
                // Anything else ends the hunk (next file header, mail signature, etc.)
                $inHunk = false;
            }
        }

        return $files;
    }

    /**
     * Strip the git a/ b/ prefix and any trailing tab+timestamp; null for /dev/null.
     */
    private function normalizePath(string $raw): ?string
    {
        $path = trim(explode("\t", $raw)[0]);
        if ($path === '' || $path === '/dev/null') {
            return null;
        }
        if (preg_match('#^[ab]/#', $path)) {
            $path = substr($path, 2);
        }
        return ltrim($path, '/');
    }
}
