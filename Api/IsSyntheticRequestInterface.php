<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Api;

/**
 * Tells whether the current storefront request comes from a Pulsar synthetic
 * checkout run rather than a shopper.
 *
 * Pulsar's checkout flow sends `X-Pulsar-Synthetic: <site id>` on every
 * request of a run. Use this to short-circuit calls that should not fire
 * for monitoring traffic — age or ID verification SDKs, fraud scoring, ERP
 * cart sync, marketing automation — without special-casing user agents.
 *
 * ```php
 * if ($this->isSyntheticRequest->execute()) {
 *     return $this->allowWithoutVerification($quote);
 * }
 * ```
 */
interface IsSyntheticRequestInterface
{
    /**
     * Whether the current request is a Pulsar synthetic run.
     */
    public function execute(): bool;

    /**
     * Pulsar site id the run belongs to, or null for a real shopper.
     */
    public function getSiteId(): ?string;
}
