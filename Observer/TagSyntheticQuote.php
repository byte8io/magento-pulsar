<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Observer;

use Byte8\Pulsar\Api\IsSyntheticRequestInterface;
use Byte8\Pulsar\Model\Synthetic\SyntheticIdentity;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

/**
 * Stamps the synthetic shopper email on every quote a Pulsar flow run
 * touches, from the first add-to-cart on. Without this the base flow (which
 * never reaches the email field) leaves anonymous guest quotes behind that
 * nothing can tell from real abandoned carts — they would count as carts in
 * the checkout funnel and never be pruned.
 */
class TagSyntheticQuote implements ObserverInterface
{
    public function __construct(
        private readonly IsSyntheticRequestInterface $isSyntheticRequest
    ) {
    }

    public function execute(Observer $observer): void
    {
        $siteId = $this->isSyntheticRequest->getSiteId();
        if ($siteId === null) {
            return;
        }
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote instanceof Quote) {
            return;
        }
        $email = $quote->getCustomerEmail();
        if (is_string($email) && SyntheticIdentity::isSyntheticEmail($email)) {
            return;
        }
        // A logged-in customer can't be a synthetic run; leave real accounts alone
        if ($quote->getCustomerId()) {
            return;
        }
        $quote->setCustomerEmail(SyntheticIdentity::email($siteId));
        $quote->setCustomerIsGuest(true);
    }
}
