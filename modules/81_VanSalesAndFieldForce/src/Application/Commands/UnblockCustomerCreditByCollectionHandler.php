<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

use Modules\VanSalesAndFieldForce\Services\CustomerFieldCreditAndReorderService;

final readonly class UnblockCustomerCreditByCollectionHandler
{
    public function __construct(private CustomerFieldCreditAndReorderService $service) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(UnblockCustomerCreditByCollectionCommand $command): array
    {
        return $this->service->unblockWithCollection(
            tenantId: $command->tenantId,
            contactId: $command->contactId,
            invoiceId: $command->invoiceId,
            amount: $command->amount,
            paymentMethod: $command->paymentMethod,
            reference: $command->reference,
            notes: $command->notes,
        );
    }
}
