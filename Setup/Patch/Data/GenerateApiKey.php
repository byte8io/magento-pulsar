<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class GenerateApiKey implements DataPatchInterface
{
    private const CONFIG_PATH = 'byte8_pulsar/general/api_key';
    private const KEY_LENGTH = 32;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly Random $random
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $existingKey = $connection->fetchOne(
            $connection->select()
                ->from($table, 'value')
                ->where('path = ?', self::CONFIG_PATH)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );

        if (empty($existingKey)) {
            $key = 'psk_' . $this->random->getRandomString(
                self::KEY_LENGTH,
                Random::CHARS_LOWERS . Random::CHARS_DIGITS
            );

            $this->configWriter->save(
                self::CONFIG_PATH,
                $this->encryptor->encrypt($key)
            );
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
