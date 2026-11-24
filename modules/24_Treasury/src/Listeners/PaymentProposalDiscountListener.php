<?php

declare(strict_types=1);

namespace Modules\Treasury\Listeners;

use Modules\Treasury\Events\PaymentExecutedEvent;
use Modules\Treasury\Services\PaymentProposalService;

/**
 * Runs synchronously, inside the transaction that records the bank outcome: the early-payment discounts of a batch born
 * from a payment proposal are recorded together with its payments.
 */
final readonly class PaymentProposalDiscountListener
{
    public function __construct(private PaymentProposalService $proposals) {}

    /**
     * Records the discounts of the executed lines of a batch born from a proposal.
     */
    public function handle(PaymentExecutedEvent $event): void
    {
        $this->proposals->recordDiscountsTaken($event);
    }
}
