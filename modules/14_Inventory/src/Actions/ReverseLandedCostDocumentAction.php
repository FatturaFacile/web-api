<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\LandedCostStatus;
use Modules\Inventory\Exceptions\InventoryValuationConflictException;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Services\InventoryRevaluationService;

final class ReverseLandedCostDocumentAction
{
    public function __construct(
        private readonly InventoryRevaluationService $revaluationService
    ) {}

    /**
     * Creates the reversal document and reversal journal for an applied landed cost (409 otherwise).
     */
    public function execute(string $documentId, string $reason, ?string $actorId): LandedCostDocument
    {
        return DB::transaction(function () use ($documentId, $reason, $actorId): LandedCostDocument {
            /** @var LandedCostDocument $document */
            $document = LandedCostDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if ($document->status !== LandedCostStatus::Applied->value || $document->reversal_of_id !== null) {
                throw new InventoryValuationConflictException("Landed cost document [{$document->number}] cannot be reversed: only applied, non-reversal documents can be reversed.");
            }

            $postingDate = $this->revaluationService->resolvePostingDate(
                (string) $document->tenant_id,
                $document->company_id !== null ? (string) $document->company_id : null,
                Carbon::now()->toDateString(),
                'reason'
            );

            $reversal = $this->revaluationService->reverseLandedCost($document, $reason, $postingDate, $actorId);

            return $reversal->refresh()->load(['receipts', 'allocations', 'revaluation']);
        });
    }
}
