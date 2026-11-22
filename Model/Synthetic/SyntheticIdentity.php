<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Synthetic;

/**
 * The shopper identity Pulsar's checkout flow uses on the shipping step.
 * Pure: shared by the request detector, the quote tagger, the pruning cron
 * and the checkout funnel collector so every piece recognises the same
 * carts.
 */
class SyntheticIdentity
{
    public const HEADER = 'X-Pulsar-Synthetic';
    public const EMAIL_DOMAIN = 'synthetic.byte8.io';
    public const EMAIL_LOCAL_PREFIX = 'pulsar+';

    /**
     * SQL LIKE pattern matching every synthetic shopper email.
     */
    public const EMAIL_LIKE = '%@' . self::EMAIL_DOMAIN;

    /**
     * Normalise a raw header value to a site id token, or null when the
     * header is absent or not something Pulsar would send. Only the
     * characters of a UUID (or the CLI's literal marker) survive, so the
     * value can be embedded in an email address safely.
     */
    public static function siteIdFromHeader(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = strtolower(trim($raw));
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        if (preg_match('/^[a-z0-9-]+$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    /**
     * Email the flow types for a site: pulsar+<site id>@synthetic.byte8.io
     */
    public static function email(string $siteId): string
    {
        return self::EMAIL_LOCAL_PREFIX . $siteId . '@' . self::EMAIL_DOMAIN;
    }

    /**
     * Whether an email belongs to the synthetic shopper.
     */
    public static function isSyntheticEmail(?string $email): bool
    {
        if ($email === null) {
            return false;
        }
        return str_ends_with(strtolower(trim($email)), '@' . self::EMAIL_DOMAIN);
    }
}
