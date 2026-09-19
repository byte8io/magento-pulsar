<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\SignatureFeed;

use Byte8\Pulsar\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Pulls the active detection-signature bundle from pulsar-server's
 * GET /signatures/bundle. Sends the shared bearer token and an If-None-Match
 * conditional so an unchanged rule set costs a cheap 304.
 *
 * This is the module's ONE outbound call to Pulsar (every other exchange has
 * Pulsar polling the module's /pulsar/health). It is best-effort: a failure
 * returns a null bundle and the caller keeps the last-known-good set — the
 * store is never left less protected than its shipped baseline.
 */
class Client
{
    private const CONNECTION_TIMEOUT = 10;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Fetch the bundle, sending $etag as If-None-Match when we have one.
     *
     * @return array{status: int, etag: ?string, bundle: ?array<string, mixed>}
     *         status 0 = transport error, 304 = unchanged, 200 = fresh bundle.
     */
    public function fetch(?string $etag): array
    {
        $endpoint = $this->config->getSignatureFeedEndpoint();
        $token = $this->config->getSignatureFeedToken();
        if ($endpoint === null || $token === null || $token === '') {
            return ['status' => 0, 'etag' => null, 'bundle' => null];
        }

        try {
            $client = $this->curlFactory->create();
            $client->setTimeout(self::CONNECTION_TIMEOUT);
            $client->addHeader('Authorization', 'Bearer ' . $token);
            $client->addHeader('Accept', 'application/json');
            if ($etag !== null && $etag !== '') {
                $client->addHeader('If-None-Match', $etag);
            }

            $client->get($endpoint);
            $status = (int) $client->getStatus();

            if ($status === 304) {
                return ['status' => 304, 'etag' => $etag, 'bundle' => null];
            }

            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Pulsar signature feed returned HTTP ' . $status);
                return ['status' => $status, 'etag' => null, 'bundle' => null];
            }

            $bundle = json_decode($client->getBody(), true);
            if (!is_array($bundle) || !isset($bundle['signatures']) || !is_array($bundle['signatures'])) {
                $this->logger->warning('Pulsar signature feed returned a malformed bundle');
                return ['status' => $status, 'etag' => null, 'bundle' => null];
            }

            return [
                'status' => $status,
                'etag' => $this->responseEtag($client),
                'bundle' => $bundle,
            ];
        } catch (\Exception $e) {
            $this->logger->warning('Pulsar signature feed fetch failed: ' . $e->getMessage());
            return ['status' => 0, 'etag' => null, 'bundle' => null];
        }
    }

    /**
     * Read the response ETag case-insensitively (header casing varies by proxy).
     */
    private function responseEtag(\Magento\Framework\HTTP\Client\Curl $client): ?string
    {
        foreach ($client->getHeaders() as $name => $value) {
            if (strtolower((string) $name) === 'etag') {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }
        return null;
    }
}
