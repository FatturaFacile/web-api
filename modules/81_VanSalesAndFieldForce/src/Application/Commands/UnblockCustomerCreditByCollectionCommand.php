<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

final readonly class UnblockCustomerCreditByCollectionCommand
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $invoiceId,
        public float $amount,
        public string $paymentMethod,
        public ?string $reference = null,
        public ?string $notes = null,
    ) {}
}
