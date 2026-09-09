<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Byte8\Pulsar\Model\Config;
use Byte8\Pulsar\Model\Patch\IdentifierExtractor;
use Byte8\Pulsar\Model\Patch\PatchVerifier;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Inventories security patches applied to the store and verifies each one is
 * actually present in the live code — regardless of mechanism:
 *
 *  - composer.json extra.patches (cweagans/composer-patches, vaimo)
 *  - vendor/composer/installed.json patches_applied metadata
 *  - archived .patch/.diff files in configured patch directories
 *    (the git-apply workflow, where no composer metadata exists)
 *
 * Advisory identifiers (CVE-*, VULN-*, APSB*-*) are extracted from filenames,
 * composer patch description keys, and patch-file comment headers. The Pulsar
 * control plane uses the verified identifier set to suppress security_advisory
 * alerts for CVEs the store has already patched in place, and a patch that is
 * archived but no longer present in the code (e.g. wiped by a composer install
 * that skipped the patch plugin) degrades this collector — turning a silent
 * security regression into an alert.
 */
class AppliedPatchesCollector implements CollectorInterface
{
    private const CACHE_KEY = 'pulsar_applied_patches';
    private const CACHE_TTL = 3600; // 1 hour; fingerprint invalidates earlier on patch/config changes
    private const MAX_PATCH_FILES = 200;
    private const MAX_PATCH_BYTES = 2097152; // 2 MB
    private const HEADER_SCAN_BYTES = 4096;

    public const SOURCE_COMPOSER_JSON = 'composer_json';
    public const SOURCE_INSTALLED_JSON = 'installed_json';
    public const SOURCE_PATCH_DIRS = 'patch_dirs';

    public function __construct(
        private readonly Config $config,
        private readonly DirectoryList $directoryList,
        private readonly CacheInterface $cache,
        private readonly PatchVerifier $verifier,
        private readonly IdentifierExtractor $identifierExtractor
    ) {
    }

    public function getName(): string
    {
        return 'applied_patches';
    }

    public function collect(): array
    {
        try {
            $root = rtrim($this->directoryList->getRoot(), '/');
            $sources = $this->config->getAppliedPatchesSources();
            $dirs = $this->config->getAppliedPatchesDirs();

            $entries = [];
            if (in_array(self::SOURCE_COMPOSER_JSON, $sources, true)) {
                $this->collectFromComposerJson($root, $entries);
            }
            if (in_array(self::SOURCE_PATCH_DIRS, $sources, true)) {
                $this->collectFromPatchDirs($root, $dirs, $entries);
            }
            if (in_array(self::SOURCE_INSTALLED_JSON, $sources, true)) {
                $this->collectFromInstalledJson($root, $entries);
            }

            $fingerprint = $this->fingerprint($root, $sources, $dirs, $entries);
            $cached = $this->loadCache($fingerprint);
            if ($cached !== null) {
                return $cached;
            }

            $data = $this->buildResult($root, $dirs, $entries);
            $data['fingerprint'] = $fingerprint;
            $this->cache->save(json_encode($data), self::CACHE_KEY, [], self::CACHE_TTL);
            unset($data['fingerprint']);
            $data['cached'] = false;

            return $data;
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_DEGRADED,
                'error' => 'Failed to inventory applied patches',
            ];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $entries keyed by patch reference (path or URL)
     */
    private function buildResult(string $root, array $dirs, array $entries): array
    {
        $patches = [];
        $verifiedIdentifiers = [];
        $declaredIdentifiers = [];
        $summary = [
            PatchVerifier::RESULT_APPLIED => 0,
            PatchVerifier::RESULT_NOT_APPLIED => 0,
            PatchVerifier::RESULT_PARTIAL => 0,
            PatchVerifier::RESULT_TARGET_MISSING => 0,
            PatchVerifier::RESULT_UNKNOWN => 0,
        ];
        $status = self::STATUS_HEALTHY;

        foreach ($entries as $ref => $entry) {
            $verification = $this->verifyEntry($root, $entry);
            $identifiers = array_values(array_unique($entry['identifiers']));

            foreach ($identifiers as $id) {
                $declaredIdentifiers[$id] = true;
                if ($verification === PatchVerifier::RESULT_APPLIED) {
                    $verifiedIdentifiers[$id] = true;
                }
            }
            $summary[$verification] = ($summary[$verification] ?? 0) + 1;

            // A patch we can see in the archive/manifest but whose changes are
            // missing from the live code means the fix was lost (typically a
            // composer install without the patch plugin) — that's the signal
            // this collector exists to raise.
            if (in_array($verification, [PatchVerifier::RESULT_NOT_APPLIED, PatchVerifier::RESULT_PARTIAL], true)) {
                $status = self::STATUS_DEGRADED;
            }

            $patches[] = [
                'file' => $ref,
                'identifiers' => $identifiers,
                'sources' => array_values(array_unique($entry['sources'])),
                'package' => $entry['package'],
                'description' => $entry['description'],
                'verification' => $verification,
            ];
        }

        return [
            'status' => $status,
            'identifiers' => array_keys($verifiedIdentifiers),
            'declared_identifiers' => array_keys($declaredIdentifiers),
            'patches' => $patches,
            'summary' => [
                'total' => count($patches),
                'applied' => $summary[PatchVerifier::RESULT_APPLIED],
                'not_applied' => $summary[PatchVerifier::RESULT_NOT_APPLIED],
                'partial' => $summary[PatchVerifier::RESULT_PARTIAL],
                'target_missing' => $summary[PatchVerifier::RESULT_TARGET_MISSING],
                'unknown' => $summary[PatchVerifier::RESULT_UNKNOWN],
            ],
            'patch_dirs' => $dirs,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function verifyEntry(string $root, array $entry): string
    {
        $abs = $entry['abs'];
        if ($abs === null || !is_file($abs)) {
            // Remote URL patch or referenced file missing — nothing to diff against.
            return PatchVerifier::RESULT_UNKNOWN;
        }
        $contents = @file_get_contents($abs, false, null, 0, self::MAX_PATCH_BYTES);
        if ($contents === false) {
            return PatchVerifier::RESULT_UNKNOWN;
        }

        $baseDirs = [$root];
        if ($entry['package'] !== null) {
            $baseDirs[] = $root . '/vendor/' . $entry['package'];
        }

        return $this->verifier->verify($contents, $baseDirs)['result'];
    }

    private function collectFromComposerJson(string $root, array &$entries): void
    {
        $composer = $this->readJson($root . '/composer.json');
        if ($composer === null) {
            return;
        }

        $patchDefs = $composer['extra']['patches'] ?? [];
        // cweagans also supports an external patches file.
        $patchesFile = $composer['extra']['patches-file'] ?? null;
        if (is_string($patchesFile)) {
            $external = $this->readJson($this->absolutePath($root, $patchesFile));
            if (isset($external['patches']) && is_array($external['patches'])) {
                $patchDefs = array_merge_recursive($patchDefs, $external['patches']);
            }
        }
        if (!is_array($patchDefs)) {
            return;
        }

        foreach ($patchDefs as $package => $defs) {
            if (!is_array($defs)) {
                continue;
            }
            foreach ($defs as $description => $pathOrUrl) {
                if (!is_string($pathOrUrl)) {
                    continue;
                }
                $description = is_string($description) ? $description : null;
                $this->addEntry($entries, $root, $pathOrUrl, self::SOURCE_COMPOSER_JSON, (string) $package, $description);
            }
        }
    }

    private function collectFromInstalledJson(string $root, array &$entries): void
    {
        $installed = $this->readJson($root . '/vendor/composer/installed.json');
        if ($installed === null) {
            return;
        }
        $packages = $installed['packages'] ?? $installed;
        if (!is_array($packages)) {
            return;
        }

        foreach ($packages as $package) {
            $applied = $package['patches_applied'] ?? ($package['extra']['patches_applied'] ?? null);
            if (!is_array($applied)) {
                continue;
            }
            foreach ($applied as $description => $pathOrUrl) {
                if (!is_string($pathOrUrl)) {
                    continue;
                }
                $description = is_string($description) ? $description : null;
                $this->addEntry(
                    $entries,
                    $root,
                    $pathOrUrl,
                    self::SOURCE_INSTALLED_JSON,
                    isset($package['name']) && is_string($package['name']) ? $package['name'] : null,
                    $description
                );
            }
        }
    }

    /**
     * @param string[] $dirs
     */
    private function collectFromPatchDirs(string $root, array $dirs, array &$entries): void
    {
        $found = 0;
        foreach ($dirs as $dir) {
            $absDir = $this->absolutePath($root, $dir);
            if (!is_dir($absDir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($found >= self::MAX_PATCH_FILES) {
                    return;
                }
                if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['patch', 'diff'], true)) {
                    continue;
                }
                $this->addEntry($entries, $root, $file->getPathname(), self::SOURCE_PATCH_DIRS, null, null);
                $found++;
            }
        }
    }

    private function addEntry(
        array &$entries,
        string $root,
        string $pathOrUrl,
        string $source,
        ?string $package,
        ?string $description
    ): void {
        $isUrl = preg_match('#^https?://#i', $pathOrUrl) === 1;
        $abs = $isUrl ? null : $this->absolutePath($root, $pathOrUrl);
        if ($abs !== null) {
            $real = realpath($abs);
            $abs = $real !== false ? $real : $abs;
        }

        // Canonical key so composer.json, installed.json, and a directory scan
        // of the same file merge into one entry (root-relative path, or the URL).
        $key = $abs !== null && str_starts_with($abs, $root . '/')
            ? substr($abs, strlen($root) + 1)
            : ($abs ?? $pathOrUrl);

        if (!isset($entries[$key])) {
            $entries[$key] = [
                'abs' => $abs,
                'package' => null,
                'description' => null,
                'sources' => [],
                'identifiers' => [],
            ];
        }

        $entry = &$entries[$key];
        $entry['sources'][] = $source;
        $entry['package'] ??= $package;
        $entry['description'] ??= $description;

        $idText = basename($pathOrUrl) . ' ' . ($description ?? '');
        if ($abs !== null && is_file($abs)) {
            $idText .= ' ' . $this->patchHeader($abs);
        }
        $entry['identifiers'] = array_merge($entry['identifiers'], $this->identifierExtractor->extract($idText));
        unset($entry);
    }

    /**
     * Comment header of a patch file (everything before the first diff/--- line);
     * some vendors note the CVE there even when the filename doesn't carry it.
     */
    private function patchHeader(string $absPath): string
    {
        $head = @file_get_contents($absPath, false, null, 0, self::HEADER_SCAN_BYTES);
        if ($head === false) {
            return '';
        }
        $cut = strlen($head);
        foreach (['diff ', '--- '] as $marker) {
            $pos = strpos($head, "\n" . $marker);
            if ($pos !== false && $pos < $cut) {
                $cut = $pos;
            }
            if (str_starts_with($head, $marker)) {
                $cut = 0;
            }
        }
        return substr($head, 0, $cut);
    }

    private function absolutePath(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $root . '/' . ltrim($path, '/');
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Cheap change signature: config + manifest mtimes + patch file mtimes.
     * Verification against vendor targets is only re-run when this changes or
     * the cache TTL lapses.
     */
    private function fingerprint(string $root, array $sources, array $dirs, array $entries): string
    {
        $parts = [
            'sources' => $sources,
            'dirs' => $dirs,
            'composer_mtime' => @filemtime($root . '/composer.json') ?: 0,
            'installed_mtime' => @filemtime($root . '/vendor/composer/installed.json') ?: 0,
            'patches' => [],
        ];
        foreach ($entries as $key => $entry) {
            $parts['patches'][$key] = $entry['abs'] !== null ? (@filemtime($entry['abs']) ?: 0) : 0;
        }
        ksort($parts['patches']);
        return md5((string) json_encode($parts));
    }

    private function loadCache(string $fingerprint): ?array
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if (!$cached) {
            return null;
        }
        $data = json_decode($cached, true);
        if (!is_array($data) || ($data['fingerprint'] ?? null) !== $fingerprint) {
            return null;
        }
        unset($data['fingerprint']);
        $data['cached'] = true;
        return $data;
    }
}
