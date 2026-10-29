<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AssetManagement\Contracts\AssetAccountingServiceInterface;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\DTOs\TransferAssetDTO;
use Modules\AssetManagement\DTOs\ValuateAssetDTO;
use Modules\AssetManagement\Events\AssetAcquisitionReadyEvent;
use Modules\AssetManagement\Events\AssetAssignedEvent;
use Modules\AssetManagement\Events\AssetCapitalizationReadyEvent;
use Modules\AssetManagement\Events\AssetCapitalizedEvent;
use Modules\AssetManagement\Events\AssetCashImpactEvent;
use Modules\AssetManagement\Events\AssetDisposalCashForecastEvent;
use Modules\AssetManagement\Events\AssetDisposalForecastEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetImpairedEvent;
use Modules\AssetManagement\Events\AssetPurchaseForecastEvent;
use Modules\AssetManagement\Events\AssetRegisteredEvent;
use Modules\AssetManagement\Events\AssetReplacementForecastEvent;
use Modules\AssetManagement\Events\AssetRevaluedEvent;
use Modules\AssetManagement\Events\AssetTransferredEvent;
use Modules\AssetManagement\Models\AssetAssignment;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetComponent;
use Modules\AssetManagement\Models\AssetCustodian;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetTransfer;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

final readonly class AssetLifecycleService implements AssetLifecycleServiceInterface
{
    public function __construct(
        private AssetAccountingServiceInterface $accountingService,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function registerAsset(RegisterAssetDTO $dto, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $category = AssetCategory::findOrFail($dto->categoryId);

            $assetNumber = $dto->assetNumber ?: 'AST-'.date('Ymd').'-'.strtoupper(Str::random(5));
            $barcodeTag = $dto->barcodeTag ?: 'TAG-'.$assetNumber;
            $lifeYears = $dto->usefulLifeYears ?? (int) $category->useful_life_years;
            $lifeMonths = $lifeYears * 12;
            $method = $dto->depreciationMethod ?? (string) $category->depreciation_method;
            $ratePercent = (float) $category->statutory_rate_percent > 0
                ? (float) $category->statutory_rate_percent
                : round(100.0 / max(1, $lifeYears), 2);

            $salvageValue = $dto->salvageValue > 0
                ? $dto->salvageValue
                : round($dto->acquisitionCost * ((float) $category->salvage_value_percent / 100.0), 2);

            $depreciableBase = max(0.0, round($dto->acquisitionCost - $salvageValue, 2));

            $asset = FixedAsset::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'category_id' => $category->id,
                'asset_class_id' => $dto->assetClassId,
                'location_id' => $dto->locationId,
                'parent_asset_id' => $dto->parentAssetId,
                'custodian_user_id' => $dto->custodianUserId,
                'project_id' => $dto->projectId,
                'supplier_id' => $dto->supplierId,
                'purchase_order_id' => $dto->purchaseOrderId,
                'purchase_receipt_id' => $dto->purchaseReceiptId,
                'inventory_item_id' => $dto->inventoryItemId,
                'asset_number' => $assetNumber,
                'barcode_tag' => $barcodeTag,
                'serial_number' => $dto->serialNumber,
                'name' => $dto->name,
                'description' => $dto->description,
                'status' => 'registered',
                'condition' => $dto->condition,
                'acquisition_type' => $dto->acquisitionType,
                'acquisition_date' => $dto->acquisitionDate ?? date('Y-m-d'),
                'currency' => $dto->currency,
                'acquisition_cost' => $dto->acquisitionCost,
                'capitalized_cost' => 0.00,
                'salvage_value' => $salvageValue,
                'depreciable_base' => $depreciableBase,
                'accumulated_depreciation' => 0.00,
                'impairment_loss_total' => 0.00,
                'revaluation_surplus_total' => 0.00,
                'carrying_amount' => $dto->acquisitionCost,
                'replacement_cost' => $dto->replacementCost > 0 ? $dto->replacementCost : $dto->acquisitionCost,
                'depreciation_method' => $method,
                'useful_life_years' => $lifeYears,
                'useful_life_months' => $lifeMonths,
                'depreciation_rate_percent' => $ratePercent,
                'total_expected_units' => $dto->totalExpectedUnits,
                'consumed_units' => 0.00,
                'is_depreciable' => (bool) $category->is_depreciable,
                'is_fully_depreciated' => false,
                'approval_status' => 'not_required',
                'is_locked' => false,
                'specifications' => $dto->specifications,
                'metadata' => $dto->metadata,
            ]);

            if ($dto->custodianUserId !== null) {
                $this->assignCustodian($asset, $dto->custodianUserId, null, $actorUserId);
            }

            $this->auditLedger->record(
                action: 'ASSET_REGISTERED',
                model: $asset,
                payloadBefore: null,
                payloadAfter: $asset->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $registeredEvent = new AssetRegisteredEvent(
                assetId: (string) $asset->id,
                assetNumber: (string) $asset->asset_number,
                name: (string) $asset->name,
                categoryId: (string) $asset->category_id,
                acquisitionCost: (float) $asset->acquisition_cost,
                acquisitionType: (string) $asset->acquisition_type,
                tenantId: $dto->tenantId,
                companyId: $dto->companyId
            );
            $this->dispatcher->publish($registeredEvent);
            event($registeredEvent);

            $acquisitionReadyEvent = new AssetAcquisitionReadyEvent(
                assetId: (string) $asset->id,
                assetNumber: (string) $asset->asset_number,
                sourceDocumentId: (string) ($dto->purchaseReceiptId ?? $dto->purchaseOrderId ?? $dto->inventoryItemId ?? $asset->id),
                sourceType: $dto->acquisitionType,
                acquisitionAmount: (float) $asset->acquisition_cost,
                currency: $dto->currency,
                tenantId: $dto->tenantId,
                companyId: $dto->companyId
            );
            $this->dispatcher->publish($acquisitionReadyEvent);
            event($acquisitionReadyEvent);

            if ($dto->acquisitionCost > 0.0) {
                $forecastEvent = new AssetPurchaseForecastEvent(
                    assetId: (string) $asset->id,
                    assetNumber: (string) $asset->asset_number,
                    expectedOutflowAmount: (float) $asset->acquisition_cost,
                    currency: $dto->currency,
                    expectedPaymentDate: date('Y-m-d', strtotime('+30 days')),
                    tenantId: $dto->tenantId,
                    companyId: $dto->companyId
                );
                $this->dispatcher->publish($forecastEvent);
                event($forecastEvent);

                $cashImpactEvent = new AssetCashImpactEvent(
                    assetId: (string) $asset->id,
                    impactType: 'acquisition',
                    cashFlowDirection: 'outflow',
                    amount: (float) $asset->acquisition_cost,
                    currency: $dto->currency,
                    effectiveDate: date('Y-m-d', strtotime('+30 days')),
                    tenantId: $dto->tenantId,
                    companyId: $dto->companyId
                );
                $this->dispatcher->publish($cashImpactEvent);
                event($cashImpactEvent);
            }

            return $asset;
        });
    }

    public function capitalizeAsset(CapitalizeAssetDTO $dto, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $lockedAsset = FixedAsset::where('id', $dto->assetId)->lockForUpdate()->firstOrFail();

            if (in_array($lockedAsset->status, ['capitalized', 'commissioned', 'in_service', 'active', 'disposed', 'sold', 'retired', 'closed'], true)) {
                throw new InvalidArgumentException("Asset [{$lockedAsset->asset_number}] is already capitalized or disposed (status: {$lockedAsset->status}).");
            }

            if ($lockedAsset->is_locked) {
                throw new InvalidArgumentException("Asset [{$lockedAsset->asset_number}] is locked by an active workflow.");
            }

            $before = $lockedAsset->toArray();
            $category = AssetCategory::findOrFail($lockedAsset->category_id);

            $capCost = $dto->capitalizedCost > 0 ? $dto->capitalizedCost : (float) $lockedAsset->acquisition_cost;
            $salvage = $dto->salvageValue >= 0 ? $dto->salvageValue : (float) $lockedAsset->salvage_value;
            $depreciableBase = max(0.0, round($capCost - $salvage, 2));
            $capDate = $dto->capitalizationDate ?: date('Y-m-d');
            $inServiceDate = $dto->inServiceDate ?: $capDate;
            $lifeYears = $dto->usefulLifeYears ?? (int) $lockedAsset->useful_life_years;
            $method = $dto->depreciationMethod ?? (string) $lockedAsset->depreciation_method;

            $capReadyEvent = new AssetCapitalizationReadyEvent(
                assetId: (string) $lockedAsset->id,
                assetNumber: (string) $lockedAsset->asset_number,
                capitalizedCost: $capCost,
                salvageValue: $salvage,
                usefulLifeMonths: $lifeYears * 12,
                depreciationMethod: $method,
                tenantId: (string) $lockedAsset->tenant_id,
                companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
            );
            $this->dispatcher->publish($capReadyEvent);
            event($capReadyEvent);

            // Check if high-value capitalization workflow definition is active
            $workflowTriggered = $this->tryStartWorkflowIfDefined(
                workflowCode: 'ASSET_CAPITALIZATION_APPROVAL',
                thresholdMet: $capCost >= 50000.0,
                approvable: $lockedAsset,
                context: [
                    'asset_number' => $lockedAsset->asset_number,
                    'capitalized_cost' => $capCost,
                ],
                tenantId: (string) $lockedAsset->tenant_id,
                actorUserId: $actorUserId
            );

            $lockedAsset->update([
                'status' => 'capitalized',
                'capitalization_date' => $capDate,
                'in_service_date' => $inServiceDate,
                'capitalized_cost' => $capCost,
                'salvage_value' => $salvage,
                'depreciable_base' => $depreciableBase,
                'carrying_amount' => $capCost,
                'useful_life_years' => $lifeYears,
                'useful_life_months' => $lifeYears * 12,
                'depreciation_method' => $method,
                'approval_status' => $workflowTriggered ? 'pending_approval' : 'approved',
            ]);

            if ($dto->autoPostToGl) {
                $this->accountingService->postCapitalizationEntry($lockedAsset, $actorUserId);
            }

            $this->auditLedger->record(
                action: 'ASSET_CAPITALIZED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: $lockedAsset->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $capEvent = new AssetCapitalizedEvent(
                assetId: (string) $lockedAsset->id,
                assetNumber: (string) $lockedAsset->asset_number,
                capitalizedCost: $capCost,
                salvageValue: $salvage,
                depreciableBase: $depreciableBase,
                capitalizationDate: $capDate,
                glAssetAccountCode: (string) ($category->gl_asset_account_code ?? '1600'),
                glClearingAccountCode: (string) ($category->gl_clearing_account_code ?? '2150'),
                tenantId: (string) $lockedAsset->tenant_id,
                companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
            );
            $this->dispatcher->publish($capEvent);
            event($capEvent);

            // Emit long-term replacement forecast for Treasury
            $replacementDate = date('Y-m-d', strtotime("+{$lifeYears} years", strtotime($capDate)));
            $replacementEvent = new AssetReplacementForecastEvent(
                assetId: (string) $lockedAsset->id,
                assetNumber: (string) $lockedAsset->asset_number,
                estimatedReplacementCost: (float) ($lockedAsset->replacement_cost > 0 ? $lockedAsset->replacement_cost : $capCost),
                currency: (string) $lockedAsset->currency,
                expectedReplacementDate: $replacementDate,
                tenantId: (string) $lockedAsset->tenant_id,
                companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
            );
            $this->dispatcher->publish($replacementEvent);
            event($replacementEvent);

            return $lockedAsset->fresh();
        });
    }

    public function commissionAsset(FixedAsset $asset, ?string $commissioningDate = null, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $commissioningDate) {
            $lockedAsset = FixedAsset::where('id', $asset->id)->lockForUpdate()->firstOrFail();
            $before = $lockedAsset->toArray();
            $commDate = $commissioningDate ?: date('Y-m-d');

            $lockedAsset->update([
                'status' => 'in_service',
                'commissioning_date' => $commDate,
                'in_service_date' => $lockedAsset->in_service_date ? $lockedAsset->in_service_date->format('Y-m-d') : $commDate,
            ]);

            $this->auditLedger->record(
                action: 'ASSET_COMMISSIONED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: $lockedAsset->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            return $lockedAsset->fresh();
        });
    }

    public function transferAsset(TransferAssetDTO $dto, ?string $actorUserId = null): AssetTransfer
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $lockedAsset = FixedAsset::where('id', $dto->assetId)->lockForUpdate()->firstOrFail();

            if (in_array($lockedAsset->status, ['disposed', 'sold', 'retired', 'closed'], true)) {
                throw new InvalidArgumentException("Cannot transfer disposed or retired asset [{$lockedAsset->asset_number}].");
            }

            $before = $lockedAsset->toArray();
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;
            $transferDate = $dto->transferDate ?: date('Y-m-d');

            $transfer = AssetTransfer::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $lockedAsset->tenant_id,
                'asset_id' => $lockedAsset->id,
                'transfer_number' => 'TRF-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'from_location_id' => $lockedAsset->location_id,
                'to_location_id' => $dto->toLocationId ?? $lockedAsset->location_id,
                'from_custodian_id' => $lockedAsset->custodian_user_id,
                'to_custodian_id' => $dto->toCustodianId ?? $lockedAsset->custodian_user_id,
                'from_project_id' => $lockedAsset->project_id,
                'to_project_id' => $dto->toProjectId ?? $lockedAsset->project_id,
                'transfer_date' => $transferDate,
                'status' => 'completed',
                'reason' => $dto->reason,
                'initiated_by_user_id' => $actorUuid,
                'completed_at' => now(),
            ]);

            $this->tryStartWorkflowIfDefined(
                workflowCode: 'ASSET_TRANSFER_APPROVAL',
                thresholdMet: true,
                approvable: $transfer,
                context: [
                    'transfer_number' => $transfer->transfer_number,
                    'asset_number' => $lockedAsset->asset_number,
                ],
                tenantId: (string) $lockedAsset->tenant_id,
                actorUserId: $actorUserId
            );

            $lockedAsset->update([
                'location_id' => $dto->toLocationId ?? $lockedAsset->location_id,
                'custodian_user_id' => $dto->toCustodianId ?? $lockedAsset->custodian_user_id,
                'project_id' => $dto->toProjectId ?? $lockedAsset->project_id,
            ]);

            if ($dto->toCustodianId !== null && $dto->toCustodianId !== $before['custodian_user_id']) {
                $this->assignCustodian($lockedAsset, $dto->toCustodianId, null, $actorUserId);
            }

            $this->auditLedger->record(
                action: 'ASSET_TRANSFERRED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: $lockedAsset->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $event = new AssetTransferredEvent(
                transferId: (string) $transfer->id,
                assetId: (string) $lockedAsset->id,
                fromLocationId: $before['location_id'] ?? null,
                toLocationId: $dto->toLocationId,
                fromCustodianId: $before['custodian_user_id'] ?? null,
                toCustodianId: $dto->toCustodianId,
                fromProjectId: $before['project_id'] ?? null,
                toProjectId: $dto->toProjectId,
                transferDate: $transferDate,
                tenantId: (string) $lockedAsset->tenant_id,
                companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
            );
            $this->dispatcher->publish($event);
            event($event);

            return $transfer->fresh();
        });
    }

    public function valuateAsset(ValuateAssetDTO $dto, ?string $actorUserId = null): AssetValuation
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $lockedAsset = FixedAsset::where('id', $dto->assetId)->lockForUpdate()->firstOrFail();

            if (in_array($lockedAsset->status, ['disposed', 'sold', 'retired', 'closed'], true)) {
                throw new InvalidArgumentException("Cannot revalue or impair a disposed asset [{$lockedAsset->asset_number}].");
            }

            $before = $lockedAsset->toArray();
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;
            $valDate = $dto->valuationDate ?: date('Y-m-d');
            $carryingBefore = (float) $lockedAsset->carrying_amount;
            $recoverableAmount = max($dto->fairValue, $dto->valueInUse);

            $revaluationReserveDelta = 0.0;
            $impairmentLoss = 0.0;
            $carryingAfter = $carryingBefore;

            if ($dto->valuationType === 'impairment') {
                // IAS 36 Impairment: Impairment Loss = max(0, Carrying Amount - Recoverable Amount)
                $impairmentLoss = max(0.0, round($carryingBefore - $recoverableAmount, 2));
                if ($impairmentLoss <= 0.0) {
                    throw new InvalidArgumentException('Recoverable amount is greater than or equal to carrying amount; no impairment loss exists.');
                }
                $carryingAfter = round($carryingBefore - $impairmentLoss, 2);
                $adjustmentAmount = -$impairmentLoss;
            } else {
                // IAS 16 Revaluation: Carrying Amount adjusted to Fair Value
                $adjustmentAmount = round($dto->fairValue - $carryingBefore, 2);
                $revaluationReserveDelta = $adjustmentAmount;
                $carryingAfter = round($dto->fairValue, 2);
            }

            $valuation = AssetValuation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $lockedAsset->tenant_id,
                'asset_id' => $lockedAsset->id,
                'valuation_number' => 'VAL-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'valuation_type' => $dto->valuationType,
                'valuation_date' => $valDate,
                'carrying_amount_before' => $carryingBefore,
                'fair_value' => $dto->fairValue,
                'value_in_use' => $dto->valueInUse,
                'recoverable_amount' => $recoverableAmount,
                'adjustment_amount' => $adjustmentAmount,
                'revaluation_reserve_delta' => $revaluationReserveDelta,
                'impairment_loss_amount' => $impairmentLoss,
                'carrying_amount_after' => $carryingAfter,
                'status' => 'approved',
                'appraiser_name' => $dto->appraiserName,
                'reason' => $dto->reason,
                'created_by_user_id' => $actorUuid,
            ]);

            $workflowCode = $dto->valuationType === 'impairment'
                ? 'ASSET_IMPAIRMENT_APPROVAL'
                : 'ASSET_REVALUATION_APPROVAL';

            $this->tryStartWorkflowIfDefined(
                workflowCode: $workflowCode,
                thresholdMet: abs($adjustmentAmount) >= 10000.0,
                approvable: $valuation,
                context: [
                    'valuation_number' => $valuation->valuation_number,
                    'asset_number' => $lockedAsset->asset_number,
                    'adjustment_amount' => $adjustmentAmount,
                ],
                tenantId: (string) $lockedAsset->tenant_id,
                actorUserId: $actorUserId
            );

            $newDepreciableBase = max(0.0, round($carryingAfter - (float) $lockedAsset->salvage_value, 2));

            $lockedAsset->update([
                'status' => $dto->valuationType === 'impairment' ? 'impaired' : 'revalued',
                'carrying_amount' => $carryingAfter,
                'depreciable_base' => $newDepreciableBase,
                'impairment_loss_total' => round((float) $lockedAsset->impairment_loss_total + $impairmentLoss, 2),
                'revaluation_surplus_total' => round((float) $lockedAsset->revaluation_surplus_total + max(0.0, $revaluationReserveDelta), 2),
            ]);

            if ($dto->autoPostToGl) {
                if ($dto->valuationType === 'impairment') {
                    $this->accountingService->postImpairmentEntry($valuation, $lockedAsset, $actorUserId);
                } else {
                    $this->accountingService->postRevaluationEntry($valuation, $lockedAsset, $actorUserId);
                }
            }

            $this->auditLedger->record(
                action: $dto->valuationType === 'impairment' ? 'ASSET_IMPAIRED' : 'ASSET_REVALUED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: $lockedAsset->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            if ($dto->valuationType === 'impairment') {
                $event = new AssetImpairedEvent(
                    valuationId: (string) $valuation->id,
                    assetId: (string) $lockedAsset->id,
                    carryingAmountBefore: $carryingBefore,
                    recoverableAmount: $recoverableAmount,
                    impairmentLossAmount: $impairmentLoss,
                    carryingAmountAfter: $carryingAfter,
                    valuationDate: $valDate,
                    tenantId: (string) $lockedAsset->tenant_id,
                    companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
                );
            } else {
                $event = new AssetRevaluedEvent(
                    valuationId: (string) $valuation->id,
                    assetId: (string) $lockedAsset->id,
                    carryingAmountBefore: $carryingBefore,
                    fairValue: $dto->fairValue,
                    revaluationSurplusDelta: $revaluationReserveDelta,
                    carryingAmountAfter: $carryingAfter,
                    valuationDate: $valDate,
                    tenantId: (string) $lockedAsset->tenant_id,
                    companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
                );
            }

            $this->dispatcher->publish($event);
            event($event);

            return $valuation->fresh();
        });
    }

    public function reverseImpairment(FixedAsset $asset, float $newRecoverableAmount, ?string $reason = null, ?string $actorUserId = null): AssetValuation
    {
        return DB::transaction(function () use ($asset, $newRecoverableAmount, $reason, $actorUserId) {
            $lockedAsset = FixedAsset::where('id', $asset->id)->lockForUpdate()->firstOrFail();

            if ((float) $lockedAsset->impairment_loss_total <= 0.0) {
                throw new InvalidArgumentException("Asset [{$lockedAsset->asset_number}] has no accumulated impairment loss to reverse.");
            }

            $carryingBefore = (float) $lockedAsset->carrying_amount;
            $maxReversal = (float) $lockedAsset->impairment_loss_total;
            $reversalAmount = min($maxReversal, max(0.0, round($newRecoverableAmount - $carryingBefore, 2)));

            if ($reversalAmount <= 0.0) {
                throw new InvalidArgumentException('New recoverable amount must exceed current carrying amount to reverse impairment.');
            }

            $carryingAfter = round($carryingBefore + $reversalAmount, 2);
            $before = $lockedAsset->toArray();
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $valuation = AssetValuation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $lockedAsset->tenant_id,
                'asset_id' => $lockedAsset->id,
                'valuation_number' => 'IMP-REV-'.date('Ymd').'-'.strtoupper(Str::random(4)),
                'valuation_type' => 'impairment_reversal',
                'valuation_date' => date('Y-m-d'),
                'carrying_amount_before' => $carryingBefore,
                'fair_value' => $newRecoverableAmount,
                'value_in_use' => $newRecoverableAmount,
                'recoverable_amount' => $newRecoverableAmount,
                'adjustment_amount' => $reversalAmount,
                'revaluation_reserve_delta' => $reversalAmount,
                'impairment_loss_amount' => 0.0,
                'carrying_amount_after' => $carryingAfter,
                'status' => 'approved',
                'reason' => $reason ?? 'IAS 36 Impairment Reversal',
                'created_by_user_id' => $actorUuid,
            ]);

            $remainingImpairment = max(0.0, round((float) $lockedAsset->impairment_loss_total - $reversalAmount, 2));

            $lockedAsset->update([
                'status' => $remainingImpairment > 0 ? 'impaired' : 'in_service',
                'carrying_amount' => $carryingAfter,
                'depreciable_base' => max(0.0, round($carryingAfter - (float) $lockedAsset->salvage_value, 2)),
                'impairment_loss_total' => $remainingImpairment,
            ]);

            $this->accountingService->postRevaluationEntry($valuation, $lockedAsset, $actorUserId);

            $this->auditLedger->record(
                action: 'ASSET_IMPAIRMENT_REVERSED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: $lockedAsset->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            return $valuation->fresh();
        });
    }

    public function disposeAsset(DisposeAssetDTO $dto, ?string $actorUserId = null): AssetDisposal
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $lockedAsset = FixedAsset::where('id', $dto->assetId)->lockForUpdate()->firstOrFail();

            if (in_array($lockedAsset->status, ['disposed', 'sold', 'retired', 'closed'], true)) {
                throw new InvalidArgumentException("Duplicate disposal prevented: Asset [{$lockedAsset->asset_number}] is already disposed or retired.");
            }

            $before = $lockedAsset->toArray();
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;
            $dispDate = $dto->disposalDate ?: date('Y-m-d');

            $assetCost = (float) ($lockedAsset->capitalized_cost > 0 ? $lockedAsset->capitalized_cost : $lockedAsset->acquisition_cost);
            $accumDepr = (float) $lockedAsset->accumulated_depreciation;
            $carryingAmount = round($assetCost - $accumDepr, 2);
            $netProceeds = round($dto->proceedsAmount - $dto->disposalCosts, 2);
            $gainLoss = round($netProceeds - $carryingAmount, 2);

            $disposal = AssetDisposal::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $lockedAsset->tenant_id,
                'asset_id' => $lockedAsset->id,
                'disposal_number' => 'DSP-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'disposal_type' => $dto->disposalType,
                'disposal_date' => $dispDate,
                'asset_cost_at_disposal' => $assetCost,
                'accumulated_depreciation_at_disposal' => $accumDepr,
                'carrying_amount_at_disposal' => $carryingAmount,
                'proceeds_amount' => $dto->proceedsAmount,
                'disposal_costs' => $dto->disposalCosts,
                'gain_loss_amount' => $gainLoss,
                'buyer_contact_id' => $dto->buyerContactId,
                'status' => 'completed',
                'reason' => $dto->reason,
                'initiated_by_user_id' => $actorUuid,
                'completed_at' => now(),
            ]);

            $this->tryStartWorkflowIfDefined(
                workflowCode: 'ASSET_DISPOSAL_APPROVAL',
                thresholdMet: $assetCost >= 10000.0,
                approvable: $disposal,
                context: [
                    'disposal_number' => $disposal->disposal_number,
                    'asset_number' => $lockedAsset->asset_number,
                    'carrying_amount' => $carryingAmount,
                ],
                tenantId: (string) $lockedAsset->tenant_id,
                actorUserId: $actorUserId
            );

            $targetStatus = match ($dto->disposalType) {
                'sale' => 'sold',
                'retirement' => 'retired',
                default => 'disposed',
            };

            $lockedAsset->update([
                'status' => $targetStatus,
                'condition' => 'decommissioned',
                'retirement_date' => $dto->disposalType === 'retirement' ? $dispDate : $lockedAsset->retirement_date,
                'disposal_date' => $dispDate,
                'carrying_amount' => 0.00,
                'is_depreciable' => false,
            ]);

            if ($dto->autoPostToGl) {
                $this->accountingService->postDisposalEntry($disposal, $lockedAsset, $actorUserId);
            }

            $this->auditLedger->record(
                action: 'ASSET_DISPOSED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: $lockedAsset->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $disposedEvent = new AssetDisposedEvent(
                disposalId: (string) $disposal->id,
                assetId: (string) $lockedAsset->id,
                disposalType: $dto->disposalType,
                assetCost: $assetCost,
                accumulatedDepreciation: $accumDepr,
                carryingAmount: $carryingAmount,
                proceedsAmount: $dto->proceedsAmount,
                gainLossAmount: $gainLoss,
                disposalDate: $dispDate,
                tenantId: (string) $lockedAsset->tenant_id,
                companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
            );
            $this->dispatcher->publish($disposedEvent);
            event($disposedEvent);

            if ($netProceeds > 0.0) {
                $expectedCollectionDate = date('Y-m-d', strtotime('+15 days', strtotime($dispDate)));

                $cashEvent = new AssetDisposalCashForecastEvent(
                    disposalId: (string) $disposal->id,
                    assetId: (string) $lockedAsset->id,
                    netProceedsAmount: $netProceeds,
                    currency: (string) $lockedAsset->currency,
                    expectedCollectionDate: $expectedCollectionDate,
                    tenantId: (string) $lockedAsset->tenant_id,
                    companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
                );
                $this->dispatcher->publish($cashEvent);
                event($cashEvent);

                $forecastEvent = new AssetDisposalForecastEvent(
                    disposalId: (string) $disposal->id,
                    assetId: (string) $lockedAsset->id,
                    expectedNetCashInflow: $netProceeds,
                    currency: (string) $lockedAsset->currency,
                    expectedReceiptDate: $expectedCollectionDate,
                    tenantId: (string) $lockedAsset->tenant_id,
                    companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
                );
                $this->dispatcher->publish($forecastEvent);
                event($forecastEvent);

                $cashImpactEvent = new AssetCashImpactEvent(
                    assetId: (string) $lockedAsset->id,
                    impactType: 'disposal_proceeds',
                    cashFlowDirection: 'inflow',
                    amount: $netProceeds,
                    currency: (string) $lockedAsset->currency,
                    effectiveDate: $expectedCollectionDate,
                    tenantId: (string) $lockedAsset->tenant_id,
                    companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
                );
                $this->dispatcher->publish($cashImpactEvent);
                event($cashImpactEvent);
            }

            return $disposal->fresh();
        });
    }

    public function attachComponent(FixedAsset $parentAsset, array $componentData, ?string $actorUserId = null): AssetComponent
    {
        return DB::transaction(function () use ($parentAsset, $componentData) {
            return AssetComponent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $parentAsset->tenant_id,
                'parent_asset_id' => $parentAsset->id,
                'component_asset_id' => $componentData['component_asset_id'] ?? null,
                'component_code' => $componentData['component_code'] ?? 'CMP-'.strtoupper(Str::random(5)),
                'name' => $componentData['name'],
                'serial_number' => $componentData['serial_number'] ?? null,
                'cost_allocation' => (float) ($componentData['cost_allocation'] ?? 0.0),
                'useful_life_months' => (int) ($componentData['useful_life_months'] ?? 36),
                'condition' => $componentData['condition'] ?? 'new',
                'status' => 'active',
                'installed_date' => $componentData['installed_date'] ?? date('Y-m-d'),
                'metadata' => $componentData['metadata'] ?? [],
            ]);
        });
    }

    public function assignCustodian(FixedAsset $asset, string $custodianUserId, ?string $department = null, ?string $actorUserId = null): AssetCustodian
    {
        return DB::transaction(function () use ($asset, $custodianUserId, $department, $actorUserId) {
            AssetCustodian::where('tenant_id', $asset->tenant_id)
                ->where('asset_id', $asset->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'returned',
                    'returned_at' => now(),
                ]);

            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $custody = AssetCustodian::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'custodian_user_id' => $custodianUserId,
                'assigned_by_user_id' => $actorUuid,
                'department' => $department,
                'assigned_at' => now(),
                'condition_on_checkout' => (string) $asset->condition,
                'status' => 'active',
            ]);

            $asset->update(['custodian_user_id' => $custodianUserId]);

            return $custody;
        });
    }

    public function assignAsset(FixedAsset $asset, array $assignmentData, ?string $actorUserId = null): AssetAssignment
    {
        return DB::transaction(function () use ($asset, $assignmentData, $actorUserId) {
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $assignment = AssetAssignment::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $asset->tenant_id,
                'company_id' => $asset->company_id ?? $asset->tenant_id,
                'asset_id' => $asset->id,
                'assignment_number' => $assignmentData['assignment_number'] ?? 'ASN-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'assignee_type' => $assignmentData['assignee_type'] ?? 'employee',
                'assignee_id' => $assignmentData['assignee_id'] ?? null,
                'assignee_name' => $assignmentData['assignee_name'] ?? 'Assigned Operator',
                'department_id' => $assignmentData['department_id'] ?? null,
                'project_id' => $assignmentData['project_id'] ?? null,
                'location_id' => $assignmentData['location_id'] ?? $asset->location_id,
                'assigned_at' => $assignmentData['assigned_at'] ?? date('Y-m-d'),
                'expected_return_at' => $assignmentData['expected_return_at'] ?? null,
                'condition_on_assignment' => (string) $asset->condition,
                'status' => 'active',
                'assigned_by' => $actorUuid,
                'notes' => $assignmentData['notes'] ?? null,
                'metadata' => $assignmentData['metadata'] ?? [],
            ]);

            $this->auditLedger->record(
                action: 'ASSET_ASSIGNED',
                model: $assignment,
                payloadBefore: null,
                payloadAfter: $assignment->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $assignedEvent = new AssetAssignedEvent(
                assignmentId: (string) $assignment->id,
                assetId: (string) $asset->id,
                assigneeType: (string) $assignment->assignee_type,
                assigneeId: $assignment->assignee_id ? (string) $assignment->assignee_id : null,
                assigneeName: (string) $assignment->assignee_name,
                assignedAt: $assignment->assigned_at ? $assignment->assigned_at->format('Y-m-d') : date('Y-m-d'),
                tenantId: (string) $asset->tenant_id,
                companyId: (string) ($asset->company_id ?? $asset->tenant_id)
            );
            $this->dispatcher->publish($assignedEvent);
            event($assignedEvent);

            return $assignment;
        });
    }

    private function tryStartWorkflowIfDefined(
        string $workflowCode,
        bool $thresholdMet,
        object $approvable,
        array $context,
        string $tenantId,
        ?string $actorUserId
    ): bool {
        if (! $thresholdMet || $this->workflowEngine === null) {
            return false;
        }

        $hasDefinition = WorkflowDefinition::where('tenant_id', $tenantId)
            ->where('code', $workflowCode)
            ->where('is_active', true)
            ->exists();

        if (! $hasDefinition) {
            return false;
        }

        try {
            $initiatorId = ! empty($actorUserId) && Str::isUuid($actorUserId)
                ? $actorUserId
                : (string) Str::uuid7();

            $instance = $this->workflowEngine->startWorkflow(new StartWorkflowDTO(
                workflowCode: $workflowCode,
                approvable: $approvable,
                contextSnapshot: $context,
                tenantId: $tenantId,
                companyId: $tenantId,
                initiatorUserId: $initiatorId
            ));

            if (method_exists($approvable, 'lockByWorkflow')) {
                $approvable->lockByWorkflow($instance);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
