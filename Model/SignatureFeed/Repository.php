<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\SignatureFeed;

use Magento\Framework\FlagManager;

/**
 * Durable last-known-good store for the detection-signature bundle, backed by
 * the shared `flag` table (survives cache flushes and is shared across app
 * nodes). Collectors read normalized signatures from here; the cron writes a
 * fresh bundle via refresh().
 */
class Repository
{
    /** flag code holding ['etag'=>?string, 'bundle'=>array, 'fetched_at'=>int]. */
    private const FLAG_CODE = 'pulsar_signature_bundle';

    /** Substring-match signature types this module knows how to apply. */
    private const SUBSTRING_TYPES = ['substring_any', 'substring_all'];

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly Client $client
    ) {
    }

    /**
     * Fetch + persist a fresh bundle. Best-effort: on 304 or any error the
     * last-known-good set is left intact.
     *
     * @return array{status: int, count: int, stored: bool}
     */
    public function refresh(): array
    {
        $result = $this->client->fetch($this->getStoredEtag());
        $status = $result['status'];

        if ($status !== 200 || !is_array($result['bundle'])) {
            return ['status' => $status, 'count' => $this->count(), 'stored' => false];
        }

        $this->flagManager->saveFlag(self::FLAG_CODE, [
            'etag' => $result['etag'],
            'bundle' => $result['bundle'],
        ]);

        return [
            'status' => $status,
            'count' => count($result['bundle']['signatures'] ?? []),
            'stored' => true,
        ];
    }

    public function getStoredEtag(): ?string
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);
        return is_array($data) && isset($data['etag']) ? (string) $data['etag'] : null;
    }

    /**
     * Normalized substring signatures for one collector, drawn from the
     * last-known-good bundle.
     *
     * @return list<array{key: string, markers: list<string>, severity: string, type: string}>
     */
    public function getSignatures(string $collector): array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);
        if (!is_array($data) || !isset($data['bundle']['signatures'])) {
            return [];
        }

        $out = [];
        foreach ((array) $data['bundle']['signatures'] as $sig) {
            if (!is_array($sig) || ($sig['collector'] ?? null) !== $collector) {
                continue;
            }
            $pattern = $sig['pattern'] ?? [];
            $type = (string) ($pattern['type'] ?? '');
            if (!in_array($type, self::SUBSTRING_TYPES, true) || !is_array($pattern['markers'] ?? null)) {
                continue; // unknown matcher shape — skip rather than mis-apply
            }

            $markers = [];
            foreach ($pattern['markers'] as $marker) {
                $marker = strtolower(trim((string) $marker));
                if ($marker !== '') {
                    $markers[] = $marker;
                }
            }
            if ($markers === []) {
                continue;
            }

            $out[] = [
                'key' => (string) ($sig['signatureKey'] ?? 'unknown'),
                'markers' => $markers,
                'severity' => (string) ($sig['severity'] ?? 'important'),
                'type' => $type,
            ];
        }

        return $out;
    }

    private function count(): int
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);
        return is_array($data) ? count($data['bundle']['signatures'] ?? []) : 0;
    }
}
