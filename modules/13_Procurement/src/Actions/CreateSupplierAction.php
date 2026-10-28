<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreateSupplierDTO;
use Modules\Procurement\Models\Supplier;

final class CreateSupplierAction
{
    public function __construct(
        private readonly ProcurementServiceInterface $procurementService
    ) {}

    public function execute(CreateSupplierDTO $dto): Supplier
    {
        return $this->procurementService->createSupplier($dto);
    }
}
