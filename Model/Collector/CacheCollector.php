<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\StateInterface;

class CacheCollector implements CollectorInterface
{
    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly StateInterface $cacheState
    ) {
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function collect(): array
    {
        $types = $this->cacheTypeList->getTypes();
        $invalidatedTypes = $this->cacheTypeList->getInvalidated();

        $cacheStatus = [];
        $disabledCount = 0;
        $invalidatedCount = count($invalidatedTypes);

        foreach ($types as $type) {
            $typeId = $type->getId();
            $isEnabled = $this->cacheState->isEnabled($typeId);
            $isInvalidated = isset($invalidatedTypes[$typeId]);

            if (!$isEnabled) {
                $disabledCount++;
            }

            // Only include key cache types in the response to keep it lightweight
            if (in_array($typeId, ['config', 'layout', 'block_html', 'full_page', 'collections'])) {
                $cacheStatus[$typeId] = [
                    'enabled' => $isEnabled,
                    'invalidated' => $isInvalidated,
                ];
            }
        }

        // Determine status
        $status = self::STATUS_HEALTHY;
        if ($invalidatedCount > 3) {
            $status = self::STATUS_DEGRADED;
        }
        if ($disabledCount > 2 || $invalidatedCount > 5) {
            $status = self::STATUS_CRITICAL;
        }

        return [
            'status' => $status,
            'total_types' => count($types),
            'disabled_count' => $disabledCount,
            'invalidated_count' => $invalidatedCount,
            'types' => $cacheStatus,
        ];
    }
}
