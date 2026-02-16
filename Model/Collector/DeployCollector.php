<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\State;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\Filesystem\DirectoryList;

class DeployCollector implements CollectorInterface
{
    private const WRITE_TEST_FILENAME = 'pulsar_write_test';

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

        // Check filesystem writability
        $varWritable = $this->testWritable(
            $this->directoryList->getPath(DirectoryList::VAR_DIR)
        );
        $staticWritable = $this->testWritable($staticPath);

        // Determine status
        $status = self::STATUS_HEALTHY;
        if ($mode === State::MODE_DEVELOPER) {
            $status = self::STATUS_CRITICAL;
        } elseif ($mode === State::MODE_DEFAULT || $maintenance) {
            $status = self::STATUS_DEGRADED;
        } elseif ($mode === State::MODE_PRODUCTION && (!$compiled || !$staticDeployed)) {
            $status = self::STATUS_DEGRADED;
        }

        // var/ not writable is critical (cache, sessions, logs all need it)
        if (!$varWritable) {
            $status = self::STATUS_CRITICAL;
        }

        // pub/static not writable in production is degraded (CSS generation fails for new visitors)
        if (!$staticWritable && $mode === State::MODE_PRODUCTION && $status !== self::STATUS_CRITICAL) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'mode' => $mode,
            'maintenance' => $maintenance,
            'compiled' => $compiled,
            'static_deployed' => $staticDeployed,
            'static_version' => $staticVersion,
            'var_writable' => $varWritable,
            'static_writable' => $staticWritable,
        ];
    }

    /**
     * Test if a directory is writable by creating and deleting a temp file
     */
    private function testWritable(string $directory): bool
    {
        $testFile = $directory . '/' . self::WRITE_TEST_FILENAME;

        try {
            $written = @file_put_contents($testFile, 'pulsar');
            if ($written === false) {
                return false;
            }
            @unlink($testFile);
            return true;
        } catch (\Exception) {
            @unlink($testFile);
            return false;
        }
    }
}
