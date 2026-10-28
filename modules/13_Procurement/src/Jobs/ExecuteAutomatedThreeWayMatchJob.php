<?php

declare(strict_types=1);

namespace Modules\Procurement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Procurement\Contracts\ThreeWayMatchingServiceInterface;
use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;

final class ExecuteAutomatedThreeWayMatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $purchaseOrderId,
        public readonly string $purchaseReceiptId,
        public readonly string $passiveInvoiceId,
        public readonly float $invoiceTotalAmount,
        public readonly string $tenantId,
        public readonly ?string $companyId = null
    ) {}

    public function handle(ThreeWayMatchingServiceInterface $matchingService): void
    {
        $dto = new ExecuteThreeWayMatchDTO(
            purchaseOrderId: $this->purchaseOrderId,
            purchaseReceiptId: $this->purchaseReceiptId,
            passiveInvoiceId: $this->passiveInvoiceId,
            invoiceTotalAmount: $this->invoiceTotalAmount,
            tenantId: $this->tenantId,
            companyId: $this->companyId
        );

        $matchingService->executeMatch($dto);
    }
}
