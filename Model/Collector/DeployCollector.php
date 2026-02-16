<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\State;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\Filesystem\DirectoryList;

class DeployCollector implements CollectorInterface
{
    public function __construct(
        private readonly State $appState,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function getName(): string
    {
        return 'deploy';
    }

    public function collect(): array
    {
        $mode = $this->appState->getMode();
        $maintenance = $this->maintenanceMode->isOn();

        // Check DI compilation (generated/metadata/global.php is the reliable indicator)
        $generatedPath = $this->directoryList->getPath(DirectoryList::GENERATED);
        $metadataFile = $generatedPath . '/metadata/global.php';
        $compiled = file_exists($metadataFile);

        // Check static content deployment
        $staticPath = $this->directoryList->getPath(DirectoryList::STATIC_VIEW);
        $versionFile = $staticPath . '/deployed_version.txt';
        $staticDeployed = file_exists($versionFile);
        $staticVersion = $staticDeployed ? trim((string) @file_get_contents($versionFile)) : null;

        // Determine status
        $status = self::STATUS_HEALTHY;
        if ($mode === State::MODE_DEVELOPER) {
            $status = self::STATUS_CRITICAL;
        } elseif ($mode === State::MODE_DEFAULT || $maintenance) {
            $status = self::STATUS_DEGRADED;
        } elseif ($mode === State::MODE_PRODUCTION && (!$compiled || !$staticDeployed)) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'mode' => $mode,
            'maintenance' => $maintenance,
            'compiled' => $compiled,
            'static_deployed' => $staticDeployed,
            'static_version' => $staticVersion,
        ];
    }
}
