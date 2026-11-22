<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Synthetic;

use Byte8\Pulsar\Api\IsSyntheticRequestInterface;
use Magento\Framework\App\RequestInterface;

/**
 * Reads the X-Pulsar-Synthetic request header. See the interface for how
 * stores use it.
 */
class IsSyntheticRequest implements IsSyntheticRequestInterface
{
    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    public function execute(): bool
    {
        return $this->getSiteId() !== null;
    }

    public function getSiteId(): ?string
    {
        if (!method_exists($this->request, 'getHeader')) {
            return null; // CLI / cron requests carry no headers
        }
        $raw = $this->request->getHeader(SyntheticIdentity::HEADER);
        return SyntheticIdentity::siteIdFromHeader(is_string($raw) ? $raw : null);
    }
}
