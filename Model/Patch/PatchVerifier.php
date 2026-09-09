<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Patch;

/**
 * Verifies that a unified diff is actually present in the live filesystem —
 * independent of how it was applied (cweagans/composer-patches, git apply,
 * Quality Patches Tool). For each file in the patch it checks that the
 * distinctive added lines exist in the target and distinctive removed-only
 * lines are gone. Whitespace is normalized so re-indented applications still
 * verify.
 */
class PatchVerifier
{
    public const RESULT_APPLIED = 'applied';
    public const RESULT_NOT_APPLIED = 'not_applied';
    public const RESULT_PARTIAL = 'partial';
    public const RESULT_TARGET_MISSING = 'target_missing';
    public const RESULT_UNKNOWN = 'unknown';

    private const MIN_DISTINCTIVE_LENGTH = 8;
    private const MIN_ALNUM_CHARS = 3;
    private const MAX_TARGET_BYTES = 2097152; // 2 MB

    public function __construct(
        private readonly UnifiedDiffParser $parser
    ) {
    }

    /**
     * @param string[] $baseDirs Candidate roots the patch paths may be relative to,
     *                           tried in order (e.g. Magento root, then vendor/<package>)
     * @return array{result: string, files: array<string, string>}
     */
    public function verify(string $patchContents, array $baseDirs): array
    {
        $files = $this->parser->parse($patchContents);
        if (!$files) {
            return ['result' => self::RESULT_UNKNOWN, 'files' => []];
        }

        $fileResults = [];
        foreach ($files as $path => $changes) {
            $fileResults[$path] = $this->verifyFile($path, $changes, $baseDirs);
        }

        return ['result' => $this->aggregate($fileResults), 'files' => $fileResults];
    }

    /**
     * @param array{added: string[], removed: string[]} $changes
     */
    private function verifyFile(string $path, array $changes, array $baseDirs): string
    {
        $target = $this->resolveTarget($path, $baseDirs);
        if ($target === null) {
            return self::RESULT_TARGET_MISSING;
        }

        $contents = @file_get_contents($target, false, null, 0, self::MAX_TARGET_BYTES);
        if ($contents === false) {
            return self::RESULT_UNKNOWN;
        }
        $haystack = $this->normalize($contents);

        $addedTrimmed = array_map('trim', $changes['added']);
        $added = $this->distinctive($changes['added']);
        $removedOnly = $this->distinctive(array_filter(
            $changes['removed'],
            static fn (string $line): bool => !in_array(trim($line), $addedTrimmed, true)
        ));

        if (!$added && !$removedOnly) {
            return self::RESULT_UNKNOWN;
        }

        $addedPresent = 0;
        foreach ($added as $line) {
            if (str_contains($haystack, $line)) {
                $addedPresent++;
            }
        }
        $removedGone = 0;
        foreach ($removedOnly as $line) {
            if (!str_contains($haystack, $line)) {
                $removedGone++;
            }
        }

        // Added lines are the strong signal; removed-only lines can legitimately
        // still appear elsewhere in the file, so they only decide when the patch
        // has no distinctive additions.
        if ($added) {
            if ($addedPresent === count($added)) {
                return self::RESULT_APPLIED;
            }
            return $addedPresent === 0 ? self::RESULT_NOT_APPLIED : self::RESULT_PARTIAL;
        }
        if ($removedGone === count($removedOnly)) {
            return self::RESULT_APPLIED;
        }
        return $removedGone === 0 ? self::RESULT_NOT_APPLIED : self::RESULT_PARTIAL;
    }

    private function resolveTarget(string $path, array $baseDirs): ?string
    {
        $candidates = [];
        foreach ($baseDirs as $base) {
            $candidates[] = rtrim($base, '/') . '/' . $path;
            // Adobe patches cut against the magento2 git repo address framework
            // code as lib/internal/…; composer installs ship it in vendor/.
            if (str_starts_with($path, 'lib/internal/Magento/Framework/')) {
                $candidates[] = rtrim($base, '/') . '/vendor/magento/framework/'
                    . substr($path, strlen('lib/internal/Magento/Framework/'));
            }
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Keep only lines with enough substance to be a reliable presence signal
     * (skips braces, blank lines, bare keywords).
     *
     * @param string[] $lines
     * @return string[] normalized
     */
    private function distinctive(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $normalized = $this->normalize($line);
            if (strlen($normalized) < self::MIN_DISTINCTIVE_LENGTH) {
                continue;
            }
            if (preg_match_all('/[a-zA-Z0-9]/', $normalized) < self::MIN_ALNUM_CHARS) {
                continue;
            }
            $out[$normalized] = true;
        }
        return array_keys($out);
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @param array<string, string> $fileResults
     */
    private function aggregate(array $fileResults): string
    {
        $definite = array_filter($fileResults, static fn (string $r): bool => in_array(
            $r,
            [self::RESULT_APPLIED, self::RESULT_NOT_APPLIED, self::RESULT_PARTIAL],
            true
        ));

        if (!$definite) {
            return in_array(self::RESULT_TARGET_MISSING, $fileResults, true)
                ? self::RESULT_TARGET_MISSING
                : self::RESULT_UNKNOWN;
        }
        $unique = array_unique(array_values($definite));
        if ($unique === [self::RESULT_APPLIED]) {
            return self::RESULT_APPLIED;
        }
        if ($unique === [self::RESULT_NOT_APPLIED]) {
            return self::RESULT_NOT_APPLIED;
        }
        return self::RESULT_PARTIAL;
    }
}
