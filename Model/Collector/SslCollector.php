<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;

class SslCollector implements CollectorInterface
{
    private const DAYS_WARNING = 30;
    private const DAYS_CRITICAL = 7;
    private const CONNECTION_TIMEOUT = 5;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'ssl';
    }

    public function collect(): array
    {
        $baseUrl = $this->scopeConfig->getValue('web/secure/base_url');

        if (empty($baseUrl)) {
            return [
                'status' => self::STATUS_DEGRADED,
                'error' => 'Secure base URL not configured',
            ];
        }

        $parsed = parse_url($baseUrl);
        $host = $parsed['host'] ?? null;
        $scheme = $parsed['scheme'] ?? 'http';

        if (!$host) {
            return [
                'status' => self::STATUS_DEGRADED,
                'error' => 'Could not parse base URL host',
            ];
        }

        if ($scheme !== 'https') {
            return [
                'status' => self::STATUS_DEGRADED,
                'host' => $host,
                'https_configured' => false,
            ];
        }

        $certInfo = $this->getCertificateInfo($host);

        if ($certInfo === null) {
            return [
                'status' => self::STATUS_CRITICAL,
                'host' => $host,
                'https_configured' => true,
                'error' => 'Could not retrieve SSL certificate',
            ];
        }

        $daysUntilExpiry = $certInfo['days_until_expiry'];

        $status = self::STATUS_HEALTHY;
        if ($daysUntilExpiry <= 0 || $daysUntilExpiry <= self::DAYS_CRITICAL) {
            $status = self::STATUS_CRITICAL;
        } elseif ($daysUntilExpiry <= self::DAYS_WARNING) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'host' => $host,
            'https_configured' => true,
            'issuer' => $certInfo['issuer'],
            'valid_from' => $certInfo['valid_from'],
            'valid_to' => $certInfo['valid_to'],
            'days_until_expiry' => $daysUntilExpiry,
        ];
    }

    private function getCertificateInfo(string $host): ?array
    {
        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $client = @stream_socket_client(
                'ssl://' . $host . ':443',
                $errno,
                $errstr,
                self::CONNECTION_TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($client === false) {
                return null;
            }

            $params = stream_context_get_params($client);
            fclose($client);

            if (!isset($params['options']['ssl']['peer_certificate'])) {
                return null;
            }

            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

            if ($cert === false) {
                return null;
            }

            $validTo = new \DateTime('@' . $cert['validTo_time_t']);
            $validFrom = new \DateTime('@' . $cert['validFrom_time_t']);
            $now = new \DateTime();
            $daysUntilExpiry = (int) $now->diff($validTo)->format('%r%a');

            $issuer = $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown';

            return [
                'issuer' => $issuer,
                'valid_from' => $validFrom->format(\DateTime::ATOM),
                'valid_to' => $validTo->format(\DateTime::ATOM),
                'days_until_expiry' => $daysUntilExpiry,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
