<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\LandedCostAllocationServiceInterface;
use Modules\Inventory\Enums\LandedCostAllocationKey;
use Modules\Inventory\Enums\LandedCostStatus;
use Modules\Inventory\Exceptions\InventoryValuationConflictException;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Services\InventoryRevaluationService;
use Modules\Inventory\Support\Decimal;

final class ApplyLandedCostDocumentAction
{
    public function __construct(
        private readonly LandedCostAllocationServiceInterface $allocationService,
        private readonly InventoryRevaluationService $revaluationService
    ) {}

    /**
     * Applies a draft landed cost: persists allocations, uplifts layers / WAC and posts the
     * inventory/COGS split journal. 409 when not draft, 422 when the period is closed.
     */
    public function execute(string $documentId, ?string $actorId): LandedCostDocument
    {
        return DB::transaction(function () use ($documentId, $actorId): LandedCostDocument {
            /** @var LandedCostDocument $document */
            $document = LandedCostDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if ($document->status !== LandedCostStatus::Draft->value) {
                throw new InventoryValuationConflictException("Landed cost document [{$document->number}] is {$document->status}; only draft documents can be applied.");
            }

            $postingDate = $this->revaluationService->resolvePostingDate(
                (string) $document->tenant_id,
                $document->company_id !== null ? (string) $document->company_id : null,
                $document->document_date->toDateString()
            );

            $total = Decimal::money(Decimal::mul((string) $document->total_amount, (string) $document->exchange_rate));
            $lines = $this->allocationService->allocate(
                LandedCostAllocationKey::from($document->allocation_key),
                $total,
                $document->receiptIds()
            );

            $revaluation = $this->revaluationService->applyLandedCost($document, $lines, $postingDate, $actorId);

            $document->forceFill([
                'status' => LandedCostStatus::Applied->value,
                'applied_at' => Carbon::now(),
                'applied_by' => $actorId,
                'journal_entry_id' => $revaluation->journal_entry_id,
                'revaluation_id' => $revaluation->id,
            ])->save();

            return $document->refresh()->load(['receipts', 'allocations', 'revaluation']);
        });
    }
}
