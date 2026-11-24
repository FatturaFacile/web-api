<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\LandedCostAllocationServiceInterface;
use Modules\Inventory\DTOs\LandedCostAllocationLine;
use Modules\Inventory\DTOs\LandedCostDocumentDTO;
use Modules\Inventory\Enums\LandedCostStatus;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Support\Decimal;

final class CreateLandedCostDocumentAction
{
    public function __construct(
        private readonly LandedCostAllocationServiceInterface $allocationService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Computes the allocation preview without persisting anything.
     *
     * @return array{allocations: list<LandedCostAllocationLine>, totals: array{allocated: string, difference: string}}
     */
    public function preview(LandedCostDocumentDTO $dto): array
    {
        $total = $dto->companyCurrencyTotal();
        $lines = $this->allocationService->allocate($dto->allocationKey, $total, $dto->receiptIds);

        $allocated = array_reduce($lines, static fn (string $carry, LandedCostAllocationLine $line): string => Decimal::add($carry, $line->allocatedAmount), '0');

        return [
            'allocations' => $lines,
            'totals' => [
                'allocated' => Decimal::money($allocated),
                'difference' => Decimal::money(Decimal::sub($total, $allocated)),
            ],
        ];
    }

    /**
     * Creates a draft document after validating that it can be allocated (422 otherwise).
     */
    public function execute(LandedCostDocumentDTO $dto): LandedCostDocument
    {
        // Validates targets/bases up front so that drafts are always allocatable at creation time.
        $this->preview($dto);

        return DB::transaction(function () use ($dto): LandedCostDocument {
            $tenantId = (string) $this->tenantContext->getTenantId();

            $document = LandedCostDocument::create([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'number' => $this->nextNumber($dto->documentDate),
                'status' => LandedCostStatus::Draft->value,
                'cost_type' => $dto->costType->value,
                'allocation_key' => $dto->allocationKey->value,
                'currency' => strtoupper($dto->currency),
                'exchange_rate' => $dto->exchangeRate,
                'total_amount' => Decimal::money($dto->totalAmount),
                'supplier_invoice_id' => $dto->supplierInvoiceId,
                'document_date' => $dto->documentDate,
                'notes' => $dto->notes,
                'created_by' => $dto->userId,
            ]);

            foreach (array_values(array_unique($dto->receiptIds)) as $receiptId) {
                $document->receipts()->create([
                    'tenant_id' => $tenantId,
                    'purchase_receipt_id' => $receiptId,
                ]);
            }

            return $document->load('receipts');
        });
    }

    private function nextNumber(string $documentDate): string
    {
        $year = Carbon::parse($documentDate)->format('Y');
        $prefix = "LC-{$year}-";

        $last = LandedCostDocument::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->where('number', 'not like', '%-R')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
