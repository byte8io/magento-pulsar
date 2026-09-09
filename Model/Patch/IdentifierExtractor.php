<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Patch;

/**
 * Extracts security-advisory identifiers (CVE / Adobe VULN / Adobe APSB) from
 * free text: patch filenames, composer.json patch description keys, or the
 * comment header of a .patch file.
 */
class IdentifierExtractor
{
    private const PATTERNS = [
        '/\bCVE-\d{4}-\d{4,7}\b/i',
        '/\bVULN-\d{3,6}\b/i',
        '/\bAPSB\d{2}-\d{1,3}\b/i',
    ];

    /**
     * @return string[] Unique identifiers, uppercased (e.g. ["CVE-2026-75650", "VULN-39341"])
     */
    public function extract(string $text): array
    {
        $found = [];
        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $id) {
                    $found[strtoupper($id)] = true;
                }
            }
        }
        return array_keys($found);
    }
}
