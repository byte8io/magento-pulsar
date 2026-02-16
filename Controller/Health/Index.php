<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Controller\Health;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Byte8\Pulsar\Model\Config;
use Byte8\Pulsar\Model\HealthCheck;

class Index implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly HealthCheck $healthCheck
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result
                ->setHttpResponseCode(503)
                ->setData([
                    'error' => 'Health endpoint is disabled',
                    'code' => 'DISABLED',
                ]);
        }

        $configApiKey = $this->config->getApiKey();
        if (empty($configApiKey)) {
            return $result
                ->setHttpResponseCode(503)
                ->setData([
                    'error' => 'API key not configured',
                    'code' => 'NOT_CONFIGURED',
                ]);
        }

        $requestApiKey = $this->getApiKeyFromRequest();
        if ($requestApiKey === null || !hash_equals($configApiKey, $requestApiKey)) {
            return $result
                ->setHttpResponseCode(401)
                ->setData([
                    'error' => 'Invalid or missing API key',
                    'code' => 'UNAUTHORIZED',
                ]);
        }

        try {
            $healthData = $this->healthCheck->check();
            return $result->setData($healthData);
        } catch (\Exception $e) {
            return $result
                ->setHttpResponseCode(500)
                ->setData([
                    'error' => 'Health check failed',
                    'code' => 'INTERNAL_ERROR',
                ]);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    private function getApiKeyFromRequest(): ?string
    {
        $pulsarKey = $this->request->getHeader('X-Pulsar-Key');
        if (!empty($pulsarKey)) {
            return $pulsarKey;
        }

        $authHeader = $this->request->getHeader('Authorization');
        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
