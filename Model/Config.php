<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_ENABLED = 'byte8_pulsar/general/enabled';
    private const XML_PATH_API_KEY = 'byte8_pulsar/general/api_key';
    private const XML_PATH_CRON_ENABLED = 'byte8_pulsar/checks/cron_enabled';
    private const XML_PATH_INDEXER_ENABLED = 'byte8_pulsar/checks/indexer_enabled';
    private const XML_PATH_CACHE_ENABLED = 'byte8_pulsar/checks/cache_enabled';
    private const XML_PATH_DATABASE_ENABLED = 'byte8_pulsar/checks/database_enabled';
    private const XML_PATH_SYSTEM_ENABLED = 'byte8_pulsar/checks/system_enabled';
    private const XML_PATH_SEARCH_ENABLED = 'byte8_pulsar/checks/search_enabled';
    private const XML_PATH_QUEUE_ENABLED = 'byte8_pulsar/checks/queue_enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getApiKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY);
        if ($value) {
            return $this->encryptor->decrypt($value);
        }
        return null;
    }

    public function isCronCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CRON_ENABLED);
    }

    public function isIndexerCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INDEXER_ENABLED);
    }

    public function isCacheCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CACHE_ENABLED);
    }

    public function isDatabaseCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DATABASE_ENABLED);
    }

    public function isSystemCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SYSTEM_ENABLED);
    }

    public function isSearchCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SEARCH_ENABLED);
    }

    public function isQueueCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_QUEUE_ENABLED);
    }

    public function isCheckEnabled(string $checkName): bool
    {
        return match ($checkName) {
            'cron' => $this->isCronCheckEnabled(),
            'indexer' => $this->isIndexerCheckEnabled(),
            'cache' => $this->isCacheCheckEnabled(),
            'database' => $this->isDatabaseCheckEnabled(),
            'system' => $this->isSystemCheckEnabled(),
            'search' => $this->isSearchCheckEnabled(),
            'queue' => $this->isQueueCheckEnabled(),
            default => false,
        };
    }
}