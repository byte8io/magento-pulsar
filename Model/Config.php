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
    private const XML_PATH_DEPLOY_ENABLED = 'byte8_pulsar/checks/deploy_enabled';
    private const XML_PATH_REDIS_ENABLED = 'byte8_pulsar/checks/redis_enabled';
    private const XML_PATH_LOG_ENABLED = 'byte8_pulsar/checks/log_enabled';
    private const XML_PATH_ADMIN_SECURITY_ENABLED = 'byte8_pulsar/checks/admin_security_enabled';
    private const XML_PATH_CONFIG_HYGIENE_ENABLED = 'byte8_pulsar/checks/config_hygiene_enabled';
    private const XML_PATH_SSL_ENABLED = 'byte8_pulsar/checks/ssl_enabled';
    private const XML_PATH_ORDERS_ENABLED = 'byte8_pulsar/checks/orders_enabled';
    private const XML_PATH_INTEGRATIONS_ENABLED = 'byte8_pulsar/checks/integrations_enabled';
    private const XML_PATH_PHPFPM_ENABLED = 'byte8_pulsar/checks/phpfpm_enabled';
    private const XML_PATH_MEDIA_INTEGRITY_ENABLED = 'byte8_pulsar/checks/media_integrity_enabled';
    private const XML_PATH_UPLOAD_ENDPOINT_ENABLED = 'byte8_pulsar/checks/upload_endpoint_enabled';
    private const XML_PATH_DATABASE_SIZE_ENABLED = 'byte8_pulsar/checks/database_size_enabled';
    private const XML_PATH_LOG_ERRORS_ENABLED = 'byte8_pulsar/checks/log_errors_enabled';
    private const XML_PATH_CONTENT_INTEGRITY_ENABLED = 'byte8_pulsar/checks/content_integrity_enabled';
    private const XML_PATH_CONTENT_INTEGRITY_ALLOWLIST = 'byte8_pulsar/checks/content_integrity_script_allowlist';
    private const XML_PATH_TRANSACTIONAL_EMAIL_ENABLED = 'byte8_pulsar/checks/transactional_email_enabled';

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

    public function isDeployCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DEPLOY_ENABLED);
    }

    public function isRedisCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REDIS_ENABLED);
    }

    public function isLogCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_ENABLED);
    }

    public function isAdminSecurityCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ADMIN_SECURITY_ENABLED);
    }

    public function isConfigHygieneCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_HYGIENE_ENABLED);
    }

    public function isSslCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SSL_ENABLED);
    }

    public function isOrdersCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ORDERS_ENABLED);
    }

    public function isIntegrationsCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INTEGRATIONS_ENABLED);
    }

    public function isPhpFpmCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PHPFPM_ENABLED);
    }

    public function isMediaIntegrityCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_MEDIA_INTEGRITY_ENABLED);
    }

    public function isUploadEndpointCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_UPLOAD_ENDPOINT_ENABLED);
    }

    public function isDatabaseSizeCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DATABASE_SIZE_ENABLED);
    }

    public function isLogErrorsCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_ERRORS_ENABLED);
    }

    public function isContentIntegrityCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONTENT_INTEGRITY_ENABLED);
    }

    /**
     * Comma/newline-separated extra hostnames the operator trusts to appear in
     * <script src> within stored content (beyond the built-in allowlist).
     *
     * @return string Raw configured value ('' if unset)
     */
    public function getContentIntegrityScriptAllowlist(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_CONTENT_INTEGRITY_ALLOWLIST);
    }

    public function isTransactionalEmailCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_TRANSACTIONAL_EMAIL_ENABLED);
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
            'deploy' => $this->isDeployCheckEnabled(),
            'redis' => $this->isRedisCheckEnabled(),
            'log' => $this->isLogCheckEnabled(),
            'admin_security' => $this->isAdminSecurityCheckEnabled(),
            'config_hygiene' => $this->isConfigHygieneCheckEnabled(),
            'ssl' => $this->isSslCheckEnabled(),
            'orders' => $this->isOrdersCheckEnabled(),
            'integrations' => $this->isIntegrationsCheckEnabled(),
            'phpfpm' => $this->isPhpFpmCheckEnabled(),
            'media_integrity' => $this->isMediaIntegrityCheckEnabled(),
            'upload_endpoint' => $this->isUploadEndpointCheckEnabled(),
            'database_size' => $this->isDatabaseSizeCheckEnabled(),
            'log_errors' => $this->isLogErrorsCheckEnabled(),
            'content_integrity' => $this->isContentIntegrityCheckEnabled(),
            'transactional_email' => $this->isTransactionalEmailCheckEnabled(),
            default => false,
        };
    }
}