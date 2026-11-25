<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Queries;

use Modules\VanSalesAndFieldForce\Services\CustomerFieldCreditAndReorderService;

final readonly class GetCustomerFastReorderAndCreditHandler
{
    public function __construct(private CustomerFieldCreditAndReorderService $service) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetCustomerFastReorderAndCreditQuery $query): array
    {
        return $this->service->getCustomerFieldDossier(
            $query->tenantId,
            $query->contactId,
            $query->proposedSaleAmount,
        );
    }
}
