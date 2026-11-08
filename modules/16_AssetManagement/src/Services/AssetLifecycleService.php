<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\AssetManagement\Exceptions\AssetOperationException;
use Modules\AssetManagement\Models\AssetAssignment;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetComponent;
use Modules\AssetManagement\Models\AssetCustodian;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetTransfer;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Support\AssetLedger;
use Modules\AssetManagement\Support\Money;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

/**
 * Lifecycle of the fixed assets. All values are decimal strings computed with bcmath; every operation runs in a
 * transaction holding the row lock of the asset; domain events go through the outbox and the in-process dispatcher.
 *
 * Approval: when the tenant defines an active approval workflow for an operation and the amount reaches the threshold,
 * the operation is stored as pending and applied only when the workflow is approved (figures recomputed then).
 */
final readonly class AssetLifecycleService implements AssetLifecycleServiceInterface
{
    /** Capitalized cost from which ASSET_CAPITALIZATION_APPROVAL applies. */
    public const string CapitalizationApprovalThreshold = '50000';

    /** Amount (adjustment, cost) from which the valuation and disposal approvals apply. */
    public const string ValuationApprovalThreshold = '10000';

    public const array ClosedStatuses = ['disposed', 'sold', 'retired', 'closed'];

    public const array CapitalizedStatuses = ['capitalized', 'commissioned', 'in_service', 'active', 'revalued', 'impaired', 'under_maintenance'];

    public function __construct(
        private AssetAccountingServiceInterface $accountingService,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private AssetBookService $books,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function registerAsset(RegisterAssetDTO $dto, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $category = AssetCategory::where('tenant_id', $dto->tenantId)->findOrFail($dto->categoryId);

            $cost = Money::round($dto->acquisitionCost);
            $lifeYears = max(1, $dto->usefulLifeYears ?? (int) $category->useful_life_years);
            $method = $dto->depreciationMethod ?? (string) $category->depreciation_method;
            $ratePercent = $dto->usefulLifeYears === null && Money::isPositive($category->statutory_rate_percent)
                ? Money::round($category->statutory_rate_percent)
                : Money::round(Money::div('100', $lifeYears));
            $salvage = Money::isPositive($dto->salvageValue)
                ? Money::round($dto->salvageValue)
                : Money::round(Money::div(Money::mul($cost, $category->salvage_value_percent ?? '0'), '100'));

            if (Money::compare($salvage, $cost) > 0) {
                throw new AssetOperationException('The salvage value cannot exceed the acquisition cost.', 'salvage_value');
            }

            $assetNumber = $dto->assetNumber ?: 'AST-'.date('Ymd').'-'.strtoupper(Str::random(6));

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
                'cost_center_id' => $dto->costCenterId,
                'supplier_id' => $dto->supplierId,
                'purchase_order_id' => $dto->purchaseOrderId,
                'purchase_order_item_id' => $dto->purchaseOrderItemId,
                'purchase_receipt_id' => $dto->purchaseReceiptId,
                'purchase_invoice_id' => $dto->purchaseInvoiceId,
                'purchase_invoice_line_id' => $dto->purchaseInvoiceLineId,
                'capitalization_account_id' => $dto->capitalizationAccountId,
                'inventory_item_id' => $dto->inventoryItemId,
                'asset_number' => $assetNumber,
                'barcode_tag' => $dto->barcodeTag ?: 'TAG-'.$assetNumber,
                'serial_number' => $dto->serialNumber,
                'name' => $dto->name,
                'description' => $dto->description,
                'status' => 'registered',
                'condition' => $dto->condition,
                'acquisition_type' => $dto->acquisitionType,
                'acquisition_date' => $dto->acquisitionDate ?? now()->toDateString(),
                'currency' => $dto->currency,
                'acquisition_cost' => $cost,
                'capitalized_cost' => '0.00',
                'salvage_value' => $salvage,
                'depreciable_base' => Money::round(Money::sub($cost, $salvage)),
                'accumulated_depreciation' => '0.00',
                'impairment_loss_total' => '0.00',
                'revaluation_surplus_total' => '0.00',
                'carrying_amount' => $cost,
                'replacement_cost' => Money::isPositive($dto->replacementCost) ? Money::round($dto->replacementCost) : $cost,
                'depreciation_method' => $method,
                'useful_life_years' => $lifeYears,
                'useful_life_months' => $lifeYears * 12,
                'depreciation_rate_percent' => $ratePercent,
                'pro_rata_convention' => $dto->proRataConvention,
                'total_expected_units' => Money::round($dto->totalExpectedUnits),
                'consumed_units' => '0.00',
                'is_depreciable' => (bool) $category->is_depreciable,
                'is_fully_depreciated' => false,
                'approval_status' => 'not_required',
                'is_locked' => false,
                'warranty_expiry_date' => $dto->warrantyExpiryDate,
                'warranty_provider' => $dto->warrantyProvider,
                'insurance_provider' => $dto->insuranceProvider,
                'insurance_policy_number' => $dto->insurancePolicyNumber,
                'insured_value' => $dto->insuredValue !== null ? Money::round($dto->insuredValue) : null,
                'insurance_expiry_date' => $dto->insuranceExpiryDate,
                'specifications' => $dto->specifications,
                'metadata' => $dto->metadata,
            ]);

            if ($dto->custodianUserId !== null) {
                $this->assignCustodian($asset, $dto->custodianUserId, null, $actorUserId);
            }

            $this->audit('ASSET_REGISTERED', $asset, null);
            $companyId = $dto->companyId;

            $this->dispatcher->publishAndDispatch(new AssetRegisteredEvent(
                assetId: (string) $asset->id,
                assetNumber: (string) $asset->asset_number,
                name: (string) $asset->name,
                categoryId: (string) $asset->category_id,
                acquisitionCost: Money::toFloat($cost),
                acquisitionType: (string) $asset->acquisition_type,
                tenantId: $dto->tenantId,
                companyId: $companyId
            ));

            $this->dispatcher->publishAndDispatch(new AssetAcquisitionReadyEvent(
                assetId: (string) $asset->id,
                assetNumber: (string) $asset->asset_number,
                sourceDocumentId: (string) ($dto->purchaseInvoiceLineId ?? $dto->purchaseReceiptId ?? $dto->purchaseOrderId ?? $dto->inventoryItemId ?? $asset->id),
                sourceType: $dto->acquisitionType,
                acquisitionAmount: Money::toFloat($cost),
                currency: $dto->currency,
                tenantId: $dto->tenantId,
                companyId: $companyId
            ));

            if (Money::isPositive($cost)) {
                $paymentDate = CarbonImmutable::parse((string) $asset->acquisition_date?->format('Y-m-d'))->addDays(30)->toDateString();

                $this->dispatcher->publishAndDispatch(new AssetPurchaseForecastEvent(
                    assetId: (string) $asset->id,
                    assetNumber: (string) $asset->asset_number,
                    expectedOutflowAmount: Money::toFloat($cost),
                    currency: $dto->currency,
                    expectedPaymentDate: $paymentDate,
                    tenantId: $dto->tenantId,
                    companyId: $companyId
                ));

                $this->dispatcher->publishAndDispatch(new AssetCashImpactEvent(
                    assetId: (string) $asset->id,
                    impactType: 'acquisition',
                    cashFlowDirection: 'outflow',
                    amount: Money::toFloat($cost),
                    currency: $dto->currency,
                    effectiveDate: $paymentDate,
                    tenantId: $dto->tenantId,
                    companyId: $companyId
                ));
            }

            return $asset->fresh();
        });
    }

    public function registerFromPurchaseDocument(string $tenantId, string $sourceType, string $sourceId, array $attributes, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($tenantId, $sourceType, $sourceId, $attributes, $actorUserId) {
            $source = match ($sourceType) {
                'passive_invoice_line' => $this->invoiceLineSource($tenantId, $sourceId),
                'purchase_order_item' => $this->orderItemSource($tenantId, $sourceId),
                default => throw new AssetOperationException("Unsupported acquisition source [{$sourceType}].", 'source_type'),
            };

            $column = $sourceType === 'passive_invoice_line' ? 'purchase_invoice_line_id' : 'purchase_order_item_id';
            $existing = FixedAsset::where('tenant_id', $tenantId)->where($column, $sourceId)->lockForUpdate()->first();
            if ($existing !== null) {
                throw new AssetOperationException("The purchase document line is already registered as asset [{$existing->asset_number}].", 'source_id');
            }

            $asset = $this->registerAsset(new RegisterAssetDTO(
                tenantId: $tenantId,
                companyId: $tenantId,
                name: (string) ($attributes['name'] ?? Str::limit($source['description'], 250, '')),
                categoryId: (string) $attributes['category_id'],
                acquisitionCost: $source['cost'],
                acquisitionType: 'purchase',
                assetClassId: $attributes['asset_class_id'] ?? null,
                locationId: $attributes['location_id'] ?? null,
                custodianUserId: $attributes['custodian_user_id'] ?? null,
                supplierId: $source['supplier_id'],
                purchaseOrderId: $source['purchase_order_id'],
                serialNumber: $attributes['serial_number'] ?? null,
                acquisitionDate: $source['date'],
                salvageValue: $attributes['salvage_value'] ?? 0,
                usefulLifeYears: isset($attributes['useful_life_years']) ? (int) $attributes['useful_life_years'] : null,
                currency: $source['currency'],
                costCenterId: $attributes['cost_center_id'] ?? null,
                purchaseInvoiceId: $source['purchase_invoice_id'],
                purchaseInvoiceLineId: $sourceType === 'passive_invoice_line' ? $sourceId : null,
                purchaseOrderItemId: $sourceType === 'purchase_order_item' ? $sourceId : null,
                capitalizationAccountId: $source['cost_account_id'],
                warrantyExpiryDate: $attributes['warranty_expiry_date'] ?? null,
            ), $actorUserId);

            if (! empty($attributes['capitalize'])) {
                return $this->capitalizeAsset(new CapitalizeAssetDTO(
                    assetId: (string) $asset->id,
                    capitalizedCost: $source['cost'],
                    salvageValue: (string) $asset->salvage_value,
                    capitalizationDate: $attributes['capitalization_date'] ?? $source['date'],
                    inServiceDate: $attributes['in_service_date'] ?? null,
                    autoPostToGl: (bool) ($attributes['auto_post_to_gl'] ?? true),
                ), $actorUserId);
            }

            return $asset;
        });
    }

    public function capitalizeAsset(CapitalizeAssetDTO $dto, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = $this->lockAsset($dto->assetId);

            if (in_array($asset->status, [...self::CapitalizedStatuses, ...self::ClosedStatuses], true)) {
                throw new AssetOperationException("Asset [{$asset->asset_number}] is already capitalized or disposed (status: {$asset->status}).");
            }
            if ($asset->is_locked) {
                throw new AssetOperationException("Asset [{$asset->asset_number}] is locked by an active workflow.");
            }

            $capCost = Money::isPositive($dto->capitalizedCost) ? Money::round($dto->capitalizedCost) : Money::round($asset->acquisition_cost);
            $pendingRequest = $this->capitalizationRequest($dto, $capCost);

            if ($this->approvalRequired('ASSET_CAPITALIZATION_APPROVAL', Money::compare($capCost, self::CapitalizationApprovalThreshold) >= 0, (string) $asset->tenant_id)) {
                $asset->update(['metadata' => [...($asset->metadata ?? []), 'pending_capitalization' => $pendingRequest]]);
                $this->startApproval('ASSET_CAPITALIZATION_APPROVAL', $asset, ['asset_number' => $asset->asset_number, 'capitalized_cost' => $capCost], $actorUserId);

                return $asset->fresh();
            }

            return $this->performCapitalization($asset, $pendingRequest, $actorUserId);
        });
    }

    public function commissionAsset(FixedAsset $asset, ?string $commissioningDate = null, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $commissioningDate) {
            $locked = $this->lockAsset((string) $asset->id);

            if (! in_array($locked->status, ['capitalized', 'commissioned'], true)) {
                throw new AssetOperationException("Only a capitalized asset can be put in service (status: {$locked->status}).");
            }

            $before = $locked->toArray();
            $date = $commissioningDate ?: now()->toDateString();

            $locked->update([
                'status' => 'in_service',
                'commissioning_date' => $date,
                'in_service_date' => $locked->in_service_date?->format('Y-m-d') ?? $date,
            ]);

            $this->audit('ASSET_COMMISSIONED', $locked, $before);

            return $locked->fresh();
        });
    }

    public function transferAsset(TransferAssetDTO $dto, ?string $actorUserId = null): AssetTransfer
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = $this->lockAsset($dto->assetId);
            $this->assertOpen($asset);

            $transfer = AssetTransfer::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'transfer_number' => 'TRF-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'from_location_id' => $asset->location_id,
                'to_location_id' => $dto->toLocationId ?? $asset->location_id,
                'from_custodian_id' => $asset->custodian_user_id,
                'to_custodian_id' => $dto->toCustodianId ?? $asset->custodian_user_id,
                'from_project_id' => $asset->project_id,
                'to_project_id' => $dto->toProjectId ?? $asset->project_id,
                'from_cost_center_id' => $asset->cost_center_id,
                'to_cost_center_id' => $dto->toCostCenterId ?? $asset->cost_center_id,
                'transfer_date' => $dto->transferDate ?: now()->toDateString(),
                'status' => 'pending',
                'reason' => $dto->reason,
                'initiated_by_user_id' => $this->actorUuid($actorUserId),
            ]);

            if ($this->approvalRequired('ASSET_TRANSFER_APPROVAL', true, (string) $asset->tenant_id)) {
                $this->startApproval('ASSET_TRANSFER_APPROVAL', $transfer, ['transfer_number' => $transfer->transfer_number, 'asset_number' => $asset->asset_number], $actorUserId);

                return $transfer->fresh();
            }

            return $this->performTransfer($asset, $transfer, $actorUserId);
        });
    }

    public function valuateAsset(ValuateAssetDTO $dto, ?string $actorUserId = null): AssetValuation
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = $this->lockAsset($dto->assetId);
            $this->assertOpen($asset);
            $this->assertCapitalized($asset);

            $valuation = AssetValuation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'valuation_number' => 'VAL-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'valuation_type' => $dto->valuationType,
                'valuation_date' => $dto->valuationDate ?: now()->toDateString(),
                'carrying_amount_before' => Money::round($asset->carrying_amount),
                'fair_value' => Money::round($dto->fairValue),
                'value_in_use' => Money::round($dto->valueInUse),
                'recoverable_amount' => Money::round(Money::max($dto->fairValue, $dto->valueInUse)),
                'adjustment_amount' => '0.00',
                'carrying_amount_after' => Money::round($asset->carrying_amount),
                'status' => 'pending',
                'appraiser_name' => $dto->appraiserName,
                'reason' => $dto->reason,
                'created_by_user_id' => $this->actorUuid($actorUserId),
                'metadata' => ['auto_post_to_gl' => $dto->autoPostToGl],
            ]);

            $estimate = $this->valuationFigures($asset, (string) $dto->valuationType, (string) $valuation->fair_value, (string) $valuation->recoverable_amount);
            $workflowCode = $dto->valuationType === 'impairment' ? 'ASSET_IMPAIRMENT_APPROVAL' : 'ASSET_REVALUATION_APPROVAL';

            if ($this->approvalRequired($workflowCode, Money::compare(Money::abs($estimate['adjustment']), self::ValuationApprovalThreshold) >= 0, (string) $asset->tenant_id)) {
                $this->startApproval($workflowCode, $valuation, ['valuation_number' => $valuation->valuation_number, 'asset_number' => $asset->asset_number, 'adjustment_amount' => $estimate['adjustment']], $actorUserId);

                return $valuation->fresh();
            }

            return $this->performValuation($asset, $valuation, $actorUserId);
        });
    }

    public function reverseImpairment(FixedAsset $asset, string|int|float $newRecoverableAmount, ?string $reason = null, ?string $actorUserId = null): AssetValuation
    {
        return DB::transaction(function () use ($asset, $newRecoverableAmount, $reason, $actorUserId) {
            $locked = $this->lockAsset((string) $asset->id);
            $this->assertOpen($locked);

            if (! Money::isPositive($locked->impairment_loss_total)) {
                throw new AssetOperationException("Asset [{$locked->asset_number}] has no accumulated impairment loss to reverse.");
            }

            $recoverable = Money::round($newRecoverableAmount);
            $carryingBefore = Money::round($locked->carrying_amount);
            $reversal = Money::round(Money::min($locked->impairment_loss_total, Money::max('0', Money::sub($recoverable, $carryingBefore))));

            if (! Money::isPositive($reversal)) {
                throw new AssetOperationException('The new recoverable amount must exceed the current carrying amount to reverse an impairment.', 'recoverable_amount');
            }

            $before = $locked->toArray();
            $carryingAfter = Money::round(Money::add($carryingBefore, $reversal));
            $remainingImpairment = Money::round(Money::sub($locked->impairment_loss_total, $reversal));
            $date = now()->toDateString();

            $valuation = AssetValuation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'asset_id' => $locked->id,
                'valuation_number' => 'IRV-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'valuation_type' => 'impairment_reversal',
                'valuation_date' => $date,
                'carrying_amount_before' => $carryingBefore,
                'fair_value' => $recoverable,
                'value_in_use' => $recoverable,
                'recoverable_amount' => $recoverable,
                'adjustment_amount' => $reversal,
                'revaluation_reserve_delta' => '0.00',
                'impairment_loss_amount' => '0.00',
                'carrying_amount_after' => $carryingAfter,
                'status' => 'approved',
                'reason' => $reason ?? 'Impairment reversal',
                'created_by_user_id' => $this->actorUuid($actorUserId),
            ]);

            $this->books->rebaseOnRemainingLife($locked, $carryingAfter, $date);
            $locked->fill([
                'status' => Money::isPositive($remainingImpairment) ? 'impaired' : 'in_service',
                'carrying_amount' => $carryingAfter,
                'impairment_loss_total' => $remainingImpairment,
                'is_fully_depreciated' => false,
            ])->save();
            $this->books->bookFor($locked, AssetLedger::Statutory);

            $this->accountingService->postImpairmentReversalEntry($valuation, $locked, $actorUserId);
            $this->audit('ASSET_IMPAIRMENT_REVERSED', $locked, $before);

            return $valuation->fresh();
        });
    }

    public function disposeAsset(DisposeAssetDTO $dto, ?string $actorUserId = null): AssetDisposal
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = $this->lockAsset($dto->assetId);
            $this->assertOpen($asset);

            $percentage = Money::round($dto->disposedPercentage, 4);
            if (! Money::isPositive($percentage) || Money::compare($percentage, '100') > 0) {
                throw new AssetOperationException('The disposed percentage must be greater than 0 and at most 100.', 'disposed_percentage');
            }

            $pending = AssetDisposal::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id)->where('status', 'pending_approval')->exists();
            if ($pending) {
                throw new AssetOperationException("A disposal of asset [{$asset->asset_number}] is already waiting for approval.");
            }

            $disposal = AssetDisposal::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'disposal_number' => 'DSP-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'disposal_type' => $dto->disposalType,
                'disposal_date' => $dto->disposalDate ?: now()->toDateString(),
                'asset_cost_at_disposal' => '0.00',
                'accumulated_depreciation_at_disposal' => '0.00',
                'carrying_amount_at_disposal' => '0.00',
                'proceeds_amount' => Money::round($dto->proceedsAmount),
                'disposal_costs' => Money::round($dto->disposalCosts),
                'gain_loss_amount' => '0.00',
                'disposed_percentage' => $percentage,
                'is_partial' => Money::compare($percentage, '100') < 0,
                'buyer_contact_id' => $dto->buyerContactId,
                'status' => 'pending',
                'reason' => $dto->reason,
                'initiated_by_user_id' => $this->actorUuid($actorUserId),
                'metadata' => ['auto_post_to_gl' => $dto->autoPostToGl],
            ]);

            $gross = Money::add($asset->carrying_amount, $asset->accumulated_depreciation);
            if ($this->approvalRequired('ASSET_DISPOSAL_APPROVAL', Money::compare($gross, self::ValuationApprovalThreshold) >= 0, (string) $asset->tenant_id)) {
                $this->startApproval('ASSET_DISPOSAL_APPROVAL', $disposal, ['disposal_number' => $disposal->disposal_number, 'asset_number' => $asset->asset_number, 'carrying_amount' => Money::round($asset->carrying_amount)], $actorUserId);

                return $disposal->fresh();
            }

            return $this->performDisposal($asset, $disposal, $actorUserId);
        });
    }

    public function approveWorkflowSubject(string $workflowInstanceId): bool
    {
        return DB::transaction(function () use ($workflowInstanceId): bool {
            $asset = FixedAsset::where('workflow_instance_id', $workflowInstanceId)->lockForUpdate()->first();
            if ($asset !== null) {
                $request = $asset->metadata['pending_capitalization'] ?? null;
                $metadata = $asset->metadata ?? [];
                unset($metadata['pending_capitalization']);
                $asset->update(['approval_status' => 'approved', 'is_locked' => false, 'metadata' => $metadata]);

                if (is_array($request) && ! in_array($asset->status, [...self::CapitalizedStatuses, ...self::ClosedStatuses], true)) {
                    $this->performCapitalization($asset, $request, $request['actor_user_id'] ?? null);
                }

                return true;
            }

            $transfer = AssetTransfer::where('workflow_instance_id', $workflowInstanceId)->lockForUpdate()->first();
            if ($transfer !== null) {
                $transfer->update(['is_locked' => false]);
                $this->performTransfer($this->lockAsset((string) $transfer->asset_id), $transfer, $transfer->initiated_by_user_id);

                return true;
            }

            $valuation = AssetValuation::where('workflow_instance_id', $workflowInstanceId)->lockForUpdate()->first();
            if ($valuation !== null) {
                $valuation->update(['is_locked' => false]);
                $asset = $this->lockAsset((string) $valuation->asset_id);
                $this->assertOpen($asset);
                $this->performValuation($asset, $valuation, $valuation->created_by_user_id);

                return true;
            }

            $disposal = AssetDisposal::where('workflow_instance_id', $workflowInstanceId)->lockForUpdate()->first();
            if ($disposal !== null) {
                $disposal->update(['is_locked' => false]);
                $asset = $this->lockAsset((string) $disposal->asset_id);
                $this->assertOpen($asset);
                $this->performDisposal($asset, $disposal, $disposal->initiated_by_user_id);

                return true;
            }

            return false;
        });
    }

    public function rejectWorkflowSubject(string $workflowInstanceId): bool
    {
        return DB::transaction(function () use ($workflowInstanceId): bool {
            $asset = FixedAsset::where('workflow_instance_id', $workflowInstanceId)->lockForUpdate()->first();
            if ($asset !== null) {
                $metadata = $asset->metadata ?? [];
                unset($metadata['pending_capitalization']);
                $asset->update(['approval_status' => 'rejected', 'is_locked' => false, 'metadata' => $metadata]);
                $this->audit('ASSET_WORKFLOW_REJECTED', $asset, null);

                return true;
            }

            foreach ([AssetTransfer::class, AssetValuation::class, AssetDisposal::class] as $class) {
                $record = $class::where('workflow_instance_id', $workflowInstanceId)->lockForUpdate()->first();
                if ($record !== null) {
                    $record->update(['status' => 'rejected', 'is_locked' => false]);
                    $this->audit('ASSET_WORKFLOW_REJECTED', $record, null);

                    return true;
                }
            }

            return false;
        });
    }

    public function attachComponent(FixedAsset $parentAsset, array $componentData, ?string $actorUserId = null): AssetComponent
    {
        return DB::transaction(function () use ($parentAsset, $componentData) {
            $asset = $this->lockAsset((string) $parentAsset->id);
            $this->assertOpen($asset);

            $allocation = Money::round($componentData['cost_allocation'] ?? '0');
            $allocated = Money::of(AssetComponent::where('tenant_id', $asset->tenant_id)->where('parent_asset_id', $asset->id)->where('status', 'active')->sum('cost_allocation'));
            $cost = Money::isPositive($asset->capitalized_cost) ? $asset->capitalized_cost : $asset->acquisition_cost;

            if (Money::compare(Money::add($allocated, $allocation), $cost) > 0) {
                throw new AssetOperationException('The cost allocated to the components cannot exceed the cost of the asset.', 'cost_allocation');
            }

            if (($componentData['component_asset_id'] ?? null) === (string) $asset->id) {
                throw new AssetOperationException('An asset cannot be a component of itself.', 'component_asset_id');
            }

            $component = AssetComponent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $asset->tenant_id,
                'parent_asset_id' => $asset->id,
                'component_asset_id' => $componentData['component_asset_id'] ?? null,
                'component_code' => $componentData['component_code'] ?? 'CMP-'.strtoupper(Str::random(6)),
                'name' => $componentData['name'],
                'serial_number' => $componentData['serial_number'] ?? null,
                'cost_allocation' => $allocation,
                'useful_life_months' => (int) ($componentData['useful_life_months'] ?? $asset->useful_life_months),
                'condition' => $componentData['condition'] ?? 'new',
                'status' => 'active',
                'installed_date' => $componentData['installed_date'] ?? now()->toDateString(),
                'metadata' => $componentData['metadata'] ?? [],
            ]);

            $this->audit('ASSET_COMPONENT_ATTACHED', $component, null);

            return $component;
        });
    }

    public function retireComponent(FixedAsset $parentAsset, string $componentId, string $status = 'replaced', ?string $actorUserId = null): AssetComponent
    {
        return DB::transaction(function () use ($parentAsset, $componentId, $status) {
            $component = AssetComponent::where('tenant_id', $parentAsset->tenant_id)
                ->where('parent_asset_id', $parentAsset->id)
                ->lockForUpdate()
                ->findOrFail($componentId);

            if ($component->status !== 'active') {
                throw new AssetOperationException("Component [{$component->component_code}] is already {$component->status}.", 'component');
            }

            $before = $component->toArray();
            $component->update(['status' => $status, 'metadata' => [...($component->metadata ?? []), 'retired_on' => now()->toDateString()]]);
            $this->audit('ASSET_COMPONENT_RETIRED', $component, $before);

            return $component->fresh();
        });
    }

    public function assignCustodian(FixedAsset $asset, string $custodianUserId, ?string $department = null, ?string $actorUserId = null): AssetCustodian
    {
        return DB::transaction(function () use ($asset, $custodianUserId, $department, $actorUserId) {
            $locked = $this->lockAsset((string) $asset->id);
            $this->assertOpen($locked);

            AssetCustodian::where('tenant_id', $locked->tenant_id)
                ->where('asset_id', $locked->id)
                ->where('status', 'active')
                ->update(['status' => 'returned', 'returned_at' => now()]);

            $custody = AssetCustodian::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'asset_id' => $locked->id,
                'custodian_user_id' => $custodianUserId,
                'assigned_by_user_id' => $this->actorUuid($actorUserId),
                'department' => $department,
                'assigned_at' => now(),
                'condition_on_checkout' => (string) $locked->condition,
                'status' => 'active',
            ]);

            $locked->update(['custodian_user_id' => $custodianUserId]);

            return $custody;
        });
    }

    public function assignAsset(FixedAsset $asset, array $assignmentData, ?string $actorUserId = null): AssetAssignment
    {
        return DB::transaction(function () use ($asset, $assignmentData, $actorUserId) {
            $locked = $this->lockAsset((string) $asset->id);
            $this->assertOpen($locked);

            AssetAssignment::where('tenant_id', $locked->tenant_id)
                ->where('asset_id', $locked->id)
                ->where('status', 'active')
                ->update(['status' => 'returned', 'returned_at' => now()->toDateString()]);

            $assignment = AssetAssignment::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id ?? $locked->tenant_id,
                'asset_id' => $locked->id,
                'assignment_number' => 'ASN-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'assignee_type' => $assignmentData['assignee_type'] ?? 'employee',
                'assignee_id' => $assignmentData['assignee_id'] ?? null,
                'assignee_name' => $assignmentData['assignee_name'],
                'department_id' => $assignmentData['department_id'] ?? null,
                'project_id' => $assignmentData['project_id'] ?? null,
                'location_id' => $assignmentData['location_id'] ?? $locked->location_id,
                'assigned_at' => $assignmentData['assigned_at'] ?? now()->toDateString(),
                'expected_return_at' => $assignmentData['expected_return_at'] ?? null,
                'condition_on_assignment' => (string) $locked->condition,
                'status' => 'active',
                'assigned_by' => $this->actorUuid($actorUserId),
                'notes' => $assignmentData['notes'] ?? null,
                'metadata' => [],
            ]);

            $this->audit('ASSET_ASSIGNED', $assignment, null);

            $this->dispatcher->publishAndDispatch(new AssetAssignedEvent(
                assignmentId: (string) $assignment->id,
                assetId: (string) $locked->id,
                assigneeType: (string) $assignment->assignee_type,
                assigneeId: $assignment->assignee_id ? (string) $assignment->assignee_id : null,
                assigneeName: (string) $assignment->assignee_name,
                assignedAt: $assignment->assigned_at?->format('Y-m-d') ?? now()->toDateString(),
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            ));

            return $assignment;
        });
    }

    public function updateAsset(FixedAsset $asset, array $attributes, ?string $actorUserId = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $attributes) {
            $locked = $this->lockAsset((string) $asset->id);
            if ($locked->is_locked) {
                throw new AssetOperationException("Asset [{$locked->asset_number}] is locked by an active workflow.");
            }

            $before = $locked->toArray();

            if (array_key_exists('warranty_expiry_date', $attributes) && $attributes['warranty_expiry_date'] !== $locked->warranty_expiry_date?->format('Y-m-d')) {
                $attributes['warranty_alerted_at'] = null;
            }
            if (array_key_exists('insurance_expiry_date', $attributes) && $attributes['insurance_expiry_date'] !== $locked->insurance_expiry_date?->format('Y-m-d')) {
                $attributes['insurance_alerted_at'] = null;
            }
            if (isset($attributes['insured_value'])) {
                $attributes['insured_value'] = Money::round($attributes['insured_value']);
            }

            $locked->update($attributes);
            $this->audit('ASSET_UPDATED', $locked, $before);

            return $locked->fresh();
        });
    }

    public function deleteAsset(FixedAsset $asset, ?string $actorUserId = null): void
    {
        DB::transaction(function () use ($asset): void {
            $locked = $this->lockAsset((string) $asset->id);

            $hasHistory = DepreciationSchedule::where('tenant_id', $locked->tenant_id)->where('asset_id', $locked->id)->exists()
                || AssetDisposal::where('tenant_id', $locked->tenant_id)->where('asset_id', $locked->id)->exists();

            if (! in_array($locked->status, ['draft', 'registered'], true) || $locked->is_locked || $hasHistory) {
                throw new AssetOperationException("Asset [{$locked->asset_number}] has been capitalized or has accounting history and cannot be deleted; dispose of it instead.");
            }

            $this->audit('ASSET_DELETED', $locked, $locked->toArray());
            $locked->delete();
        });
    }

    /**
     * Capitalizes the locked asset with a stored request (direct call or approved workflow).
     *
     * @param  array<string, mixed>  $request
     */
    private function performCapitalization(FixedAsset $asset, array $request, ?string $actorUserId): FixedAsset
    {
        $before = $asset->toArray();
        $category = AssetCategory::where('tenant_id', $asset->tenant_id)->findOrFail($asset->category_id);
        $capCost = (string) $request['capitalized_cost'];
        $salvage = (string) $request['salvage_value'];

        if (Money::compare($salvage, $capCost) > 0) {
            throw new AssetOperationException('The salvage value cannot exceed the capitalized cost.', 'salvage_value');
        }

        $lifeYears = max(1, (int) ($request['useful_life_years'] ?? $asset->useful_life_years));
        $method = (string) ($request['depreciation_method'] ?? $asset->depreciation_method);
        $capDate = (string) $request['capitalization_date'];
        $inServiceDate = (string) ($request['in_service_date'] ?? $capDate);
        $companyId = (string) ($asset->company_id ?? $asset->tenant_id);

        $this->dispatcher->publishAndDispatch(new AssetCapitalizationReadyEvent(
            assetId: (string) $asset->id,
            assetNumber: (string) $asset->asset_number,
            capitalizedCost: Money::toFloat($capCost),
            salvageValue: Money::toFloat($salvage),
            usefulLifeMonths: $lifeYears * 12,
            depreciationMethod: $method,
            tenantId: (string) $asset->tenant_id,
            companyId: $companyId
        ));

        $asset->update([
            'status' => 'capitalized',
            'capitalization_date' => $capDate,
            'in_service_date' => $inServiceDate,
            'depreciation_start_date' => $request['depreciation_start_date'] ?? $inServiceDate,
            'pro_rata_convention' => $request['pro_rata_convention'] ?? $asset->pro_rata_convention,
            'capitalized_cost' => $capCost,
            'salvage_value' => $salvage,
            'depreciable_base' => Money::round(Money::max('0', Money::sub($capCost, $salvage))),
            'carrying_amount' => $capCost,
            'useful_life_years' => $lifeYears,
            'useful_life_months' => $lifeYears * 12,
            'depreciation_method' => $method,
            'approval_status' => $asset->approval_status === 'not_required' ? 'not_required' : 'approved',
        ]);

        $this->books->openBooks($asset);

        if ((bool) ($request['auto_post_to_gl'] ?? true)) {
            $this->accountingService->postCapitalizationEntry($asset, $actorUserId);
        }

        $this->audit('ASSET_CAPITALIZED', $asset, $before);

        $this->dispatcher->publishAndDispatch(new AssetCapitalizedEvent(
            assetId: (string) $asset->id,
            assetNumber: (string) $asset->asset_number,
            capitalizedCost: Money::toFloat($capCost),
            salvageValue: Money::toFloat($salvage),
            depreciableBase: Money::toFloat($asset->depreciable_base),
            capitalizationDate: $capDate,
            glAssetAccountCode: (string) ($category->gl_asset_account_code ?? '1600'),
            glClearingAccountCode: (string) ($category->gl_clearing_account_code ?? '2150'),
            tenantId: (string) $asset->tenant_id,
            companyId: $companyId
        ));

        $this->dispatcher->publishAndDispatch(new AssetReplacementForecastEvent(
            assetId: (string) $asset->id,
            assetNumber: (string) $asset->asset_number,
            estimatedReplacementCost: Money::toFloat(Money::isPositive($asset->replacement_cost) ? $asset->replacement_cost : $capCost),
            currency: (string) $asset->currency,
            expectedReplacementDate: CarbonImmutable::parse($capDate)->addYears($lifeYears)->toDateString(),
            tenantId: (string) $asset->tenant_id,
            companyId: $companyId
        ));

        return $asset->fresh();
    }

    /**
     * Moves the locked asset as the transfer says and completes it.
     */
    private function performTransfer(FixedAsset $asset, AssetTransfer $transfer, ?string $actorUserId): AssetTransfer
    {
        $this->assertOpen($asset);
        $before = $asset->toArray();

        $asset->update([
            'location_id' => $transfer->to_location_id,
            'project_id' => $transfer->to_project_id,
            'cost_center_id' => $transfer->to_cost_center_id,
        ]);

        if ($transfer->to_custodian_id !== null && $transfer->to_custodian_id !== $before['custodian_user_id']) {
            $this->assignCustodian($asset, (string) $transfer->to_custodian_id, null, $actorUserId);
        }

        $transfer->update(['status' => 'completed', 'completed_at' => now()]);
        $this->audit('ASSET_TRANSFERRED', $asset, $before);

        $this->dispatcher->publishAndDispatch(new AssetTransferredEvent(
            transferId: (string) $transfer->id,
            assetId: (string) $asset->id,
            fromLocationId: $transfer->from_location_id,
            toLocationId: $transfer->to_location_id,
            fromCustodianId: $transfer->from_custodian_id,
            toCustodianId: $transfer->to_custodian_id,
            fromProjectId: $transfer->from_project_id,
            toProjectId: $transfer->to_project_id,
            transferDate: (string) $transfer->transfer_date?->format('Y-m-d'),
            tenantId: (string) $asset->tenant_id,
            companyId: (string) ($asset->company_id ?? $asset->tenant_id)
        ));

        return $transfer->fresh();
    }

    /**
     * Computes and applies a stored valuation on the locked asset.
     */
    private function performValuation(FixedAsset $asset, AssetValuation $valuation, ?string $actorUserId): AssetValuation
    {
        $before = $asset->toArray();
        $type = (string) $valuation->valuation_type;
        $figures = $this->valuationFigures($asset, $type, (string) $valuation->fair_value, (string) $valuation->recoverable_amount);
        $date = (string) $valuation->valuation_date?->format('Y-m-d');

        $valuation->update([
            'carrying_amount_before' => $figures['before'],
            'adjustment_amount' => $figures['adjustment'],
            'revaluation_reserve_delta' => $figures['reserve'],
            'impairment_loss_amount' => $type === 'impairment' ? $figures['profit_loss'] : '0.00',
            'carrying_amount_after' => $figures['after'],
            'status' => 'approved',
            'metadata' => [...($valuation->metadata ?? []), 'profit_loss_amount' => $figures['profit_loss']],
        ]);

        $impairmentDelta = match (true) {
            $type === 'impairment' => $figures['profit_loss'],
            Money::isPositive($figures['adjustment']) => Money::sub('0', $figures['profit_loss']),
            default => $figures['profit_loss'],
        };

        $this->books->rebaseOnRemainingLife($asset, $figures['after'], $date);
        $asset->fill([
            'status' => $type === 'impairment' ? 'impaired' : 'revalued',
            'carrying_amount' => $figures['after'],
            'impairment_loss_total' => Money::round(Money::max('0', Money::add($asset->impairment_loss_total, $impairmentDelta))),
            'revaluation_surplus_total' => Money::round(Money::max('0', Money::add($asset->revaluation_surplus_total, $figures['reserve']))),
            'is_fully_depreciated' => Money::compare($figures['after'], $asset->salvage_value) <= 0,
        ])->save();
        $this->books->bookFor($asset, AssetLedger::Statutory);

        if ((bool) ($valuation->metadata['auto_post_to_gl'] ?? true)) {
            if ($type === 'impairment' && Money::isZero($figures['reserve'])) {
                $this->accountingService->postImpairmentEntry($valuation, $asset, $actorUserId);
            } else {
                $this->accountingService->postRevaluationEntry($valuation, $asset, $actorUserId);
            }
        }

        $this->audit($type === 'impairment' ? 'ASSET_IMPAIRED' : 'ASSET_REVALUED', $asset, $before);
        $companyId = (string) ($asset->company_id ?? $asset->tenant_id);

        $this->dispatcher->publishAndDispatch($type === 'impairment'
            ? new AssetImpairedEvent(
                valuationId: (string) $valuation->id,
                assetId: (string) $asset->id,
                carryingAmountBefore: Money::toFloat($figures['before']),
                recoverableAmount: Money::toFloat($valuation->recoverable_amount),
                impairmentLossAmount: Money::toFloat(Money::abs($figures['adjustment'])),
                carryingAmountAfter: Money::toFloat($figures['after']),
                valuationDate: $date,
                tenantId: (string) $asset->tenant_id,
                companyId: $companyId
            )
            : new AssetRevaluedEvent(
                valuationId: (string) $valuation->id,
                assetId: (string) $asset->id,
                carryingAmountBefore: Money::toFloat($figures['before']),
                fairValue: Money::toFloat($valuation->fair_value),
                revaluationSurplusDelta: Money::toFloat($figures['reserve']),
                carryingAmountAfter: Money::toFloat($figures['after']),
                valuationDate: $date,
                tenantId: (string) $asset->tenant_id,
                companyId: $companyId
            ));

        return $valuation->fresh();
    }

    /**
     * Figures of a valuation on the current statutory values: carrying amount before and after, total adjustment
     * (signed), the part going to the revaluation reserve (signed) and the part going to profit or loss (unsigned).
     *
     * Impairment: loss = carrying - recoverable, charged first against the revaluation surplus of the asset.
     * Revaluation up: first reverses losses recognised earlier in profit or loss, the rest to the reserve.
     * Revaluation down: first reduces the surplus of the asset, the rest is a loss.
     *
     * @return array{before: string, after: string, adjustment: string, reserve: string, profit_loss: string}
     */
    private function valuationFigures(FixedAsset $asset, string $type, string $fairValue, string $recoverableAmount): array
    {
        $before = Money::round($asset->carrying_amount);
        $surplus = Money::of($asset->revaluation_surplus_total);

        if ($type === 'impairment') {
            $loss = Money::round(Money::sub($before, $recoverableAmount));
            if (! Money::isPositive($loss)) {
                throw new AssetOperationException('The recoverable amount is greater than or equal to the carrying amount: there is no impairment loss.', 'fair_value');
            }

            $reservePart = Money::round(Money::min($loss, $surplus));

            return ['before' => $before, 'after' => Money::round(Money::sub($before, $loss)), 'adjustment' => Money::round(Money::sub('0', $loss)), 'reserve' => Money::round(Money::sub('0', $reservePart)), 'profit_loss' => Money::round(Money::sub($loss, $reservePart))];
        }

        $delta = Money::round(Money::sub($fairValue, $before));
        if (Money::isZero($delta)) {
            throw new AssetOperationException('The fair value equals the carrying amount: there is nothing to revalue.', 'fair_value');
        }

        if (Money::isPositive($delta)) {
            $profitLoss = Money::round(Money::min($delta, $asset->impairment_loss_total));

            return ['before' => $before, 'after' => Money::round($fairValue), 'adjustment' => $delta, 'reserve' => Money::round(Money::sub($delta, $profitLoss)), 'profit_loss' => $profitLoss];
        }

        $decrease = Money::abs($delta);
        $reservePart = Money::round(Money::min($decrease, $surplus));

        return ['before' => $before, 'after' => Money::round($fairValue), 'adjustment' => $delta, 'reserve' => Money::round(Money::sub('0', $reservePart)), 'profit_loss' => Money::round(Money::sub($decrease, $reservePart))];
    }

    /**
     * Computes and applies a stored disposal on the locked asset: the disposed share of every book is derecognised,
     * with gain or loss on the statutory values; a total disposal closes the asset.
     */
    private function performDisposal(FixedAsset $asset, AssetDisposal $disposal, ?string $actorUserId): AssetDisposal
    {
        $before = $asset->toArray();
        $fraction = Money::div($disposal->disposed_percentage, '100');
        $isTotal = Money::compare($fraction, '1') >= 0;
        $remaining = Money::sub('1', $fraction);

        $carrying = Money::round(Money::mul($asset->carrying_amount, $fraction));
        $accumulated = Money::round(Money::mul($asset->accumulated_depreciation, $fraction));
        $gross = Money::round(Money::add($carrying, $accumulated));
        $netProceeds = Money::round(Money::sub($disposal->proceeds_amount, $disposal->disposal_costs));
        $gainLoss = Money::round(Money::sub($netProceeds, $carrying));
        $date = (string) $disposal->disposal_date?->format('Y-m-d');

        $disposal->update([
            'asset_cost_at_disposal' => $gross,
            'accumulated_depreciation_at_disposal' => $accumulated,
            'carrying_amount_at_disposal' => $carrying,
            'gain_loss_amount' => $gainLoss,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $disposal->update(['metadata' => [...($disposal->metadata ?? []), 'book_shares' => $this->books->disposeShare($asset, $fraction)]]);

        $scaled = static fn (mixed $value): string => Money::round(Money::mul($value ?? '0', $remaining));
        $updates = [
            'acquisition_cost' => $scaled($asset->acquisition_cost),
            'capitalized_cost' => $scaled($asset->capitalized_cost),
            'salvage_value' => $scaled($asset->salvage_value),
            'depreciable_base' => $scaled($asset->depreciable_base),
            'accumulated_depreciation' => $scaled($asset->accumulated_depreciation),
            'carrying_amount' => $scaled($asset->carrying_amount),
            'impairment_loss_total' => $scaled($asset->impairment_loss_total),
            'revaluation_surplus_total' => $scaled($asset->revaluation_surplus_total),
            'total_expected_units' => $scaled($asset->total_expected_units),
        ];

        if ($isTotal) {
            $updates = [
                'status' => match ($disposal->disposal_type) {
                    'sale' => 'sold',
                    'retirement' => 'retired',
                    default => 'disposed',
                },
                'condition' => 'decommissioned',
                'retirement_date' => $disposal->disposal_type === 'retirement' ? $date : $asset->retirement_date?->format('Y-m-d'),
                'disposal_date' => $date,
                'carrying_amount' => '0.00',
                'is_depreciable' => false,
            ];
        }

        $asset->update($updates);

        if ((bool) ($disposal->metadata['auto_post_to_gl'] ?? true)) {
            $this->accountingService->postDisposalEntry($disposal->fresh(), $asset, $actorUserId);
        }

        $this->audit('ASSET_DISPOSED', $asset, $before);
        $this->publishDisposalEvents($asset, $disposal->fresh(), $netProceeds);

        return $disposal->fresh();
    }

    /**
     * Disposal event plus the cash forecasts of the net proceeds.
     */
    private function publishDisposalEvents(FixedAsset $asset, AssetDisposal $disposal, string $netProceeds): void
    {
        $tenantId = (string) $asset->tenant_id;
        $companyId = (string) ($asset->company_id ?? $asset->tenant_id);
        $date = (string) $disposal->disposal_date?->format('Y-m-d');

        $this->dispatcher->publishAndDispatch(new AssetDisposedEvent(
            disposalId: (string) $disposal->id,
            assetId: (string) $asset->id,
            disposalType: (string) $disposal->disposal_type,
            assetCost: Money::toFloat($disposal->asset_cost_at_disposal),
            accumulatedDepreciation: Money::toFloat($disposal->accumulated_depreciation_at_disposal),
            carryingAmount: Money::toFloat($disposal->carrying_amount_at_disposal),
            proceedsAmount: Money::toFloat($disposal->proceeds_amount),
            gainLossAmount: Money::toFloat($disposal->gain_loss_amount),
            disposalDate: $date,
            tenantId: $tenantId,
            companyId: $companyId
        ));

        if (! Money::isPositive($netProceeds)) {
            return;
        }

        $collectionDate = CarbonImmutable::parse($date)->addDays(15)->toDateString();
        $amount = Money::toFloat($netProceeds);

        $this->dispatcher->publishAndDispatch(new AssetDisposalCashForecastEvent(
            disposalId: (string) $disposal->id,
            assetId: (string) $asset->id,
            netProceedsAmount: $amount,
            currency: (string) $asset->currency,
            expectedCollectionDate: $collectionDate,
            tenantId: $tenantId,
            companyId: $companyId
        ));
        $this->dispatcher->publishAndDispatch(new AssetDisposalForecastEvent(
            disposalId: (string) $disposal->id,
            assetId: (string) $asset->id,
            expectedNetCashInflow: $amount,
            currency: (string) $asset->currency,
            expectedReceiptDate: $collectionDate,
            tenantId: $tenantId,
            companyId: $companyId
        ));
        $this->dispatcher->publishAndDispatch(new AssetCashImpactEvent(
            assetId: (string) $asset->id,
            impactType: 'disposal_proceeds',
            cashFlowDirection: 'inflow',
            amount: $amount,
            currency: (string) $asset->currency,
            effectiveDate: $collectionDate,
            tenantId: $tenantId,
            companyId: $companyId
        ));
    }

    /**
     * The capitalization parameters, stored as they are when an approval is pending.
     *
     * @return array<string, mixed>
     */
    private function capitalizationRequest(CapitalizeAssetDTO $dto, string $capCost): array
    {
        $capDate = $dto->capitalizationDate ?: now()->toDateString();

        return [
            'capitalized_cost' => $capCost,
            'salvage_value' => Money::round($dto->salvageValue),
            'capitalization_date' => $capDate,
            'in_service_date' => $dto->inServiceDate ?: $capDate,
            'depreciation_start_date' => $dto->depreciationStartDate,
            'pro_rata_convention' => $dto->proRataConvention,
            'depreciation_method' => $dto->depreciationMethod,
            'useful_life_years' => $dto->usefulLifeYears,
            'auto_post_to_gl' => $dto->autoPostToGl,
        ];
    }

    /**
     * Source data of a passive invoice line of the tenant.
     *
     * @return array{description: string, cost: string, supplier_id: ?string, purchase_order_id: ?string, purchase_invoice_id: ?string, cost_account_id: ?string, date: ?string, currency: string}
     */
    private function invoiceLineSource(string $tenantId, string $lineId): array
    {
        $line = PassiveInvoiceLine::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($lineId)
            ?? throw new AssetOperationException('Passive invoice line not found in the active company.', 'source_id');
        $invoice = PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($line->passive_invoice_id)
            ?? throw new AssetOperationException('Passive invoice not found in the active company.', 'source_id');

        return [
            'description' => (string) $line->description,
            'cost' => Money::round($line->line_total),
            'supplier_id' => (string) $invoice->supplier_id,
            'purchase_order_id' => null,
            'purchase_invoice_id' => (string) $invoice->id,
            'cost_account_id' => filled($line->cost_account_id) ? (string) $line->cost_account_id : null,
            'date' => filled($invoice->date) ? CarbonImmutable::parse((string) $invoice->date)->toDateString() : null,
            'currency' => (string) ($invoice->currency ?: 'EUR'),
        ];
    }

    /**
     * Source data of a purchase order item of the tenant.
     *
     * @return array{description: string, cost: string, supplier_id: ?string, purchase_order_id: ?string, purchase_invoice_id: ?string, cost_account_id: ?string, date: ?string, currency: string}
     */
    private function orderItemSource(string $tenantId, string $itemId): array
    {
        $item = PurchaseOrderItem::query()->find($itemId)
            ?? throw new AssetOperationException('Purchase order item not found.', 'source_id');
        $order = PurchaseOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($item->purchase_order_id)
            ?? throw new AssetOperationException('Purchase order item not found in the active company.', 'source_id');

        return [
            'description' => (string) $item->description,
            'cost' => Money::round($item->line_total),
            'supplier_id' => (string) $order->supplier_id,
            'purchase_order_id' => (string) $order->id,
            'purchase_invoice_id' => null,
            'cost_account_id' => null,
            'date' => filled($order->date) ? CarbonImmutable::parse((string) $order->date)->toDateString() : null,
            'currency' => (string) ($order->currency ?: 'EUR'),
        ];
    }

    /**
     * Whether an approval workflow must decide: an active definition exists and the threshold is reached.
     */
    private function approvalRequired(string $workflowCode, bool $thresholdMet, string $tenantId): bool
    {
        return $thresholdMet
            && $this->workflowEngine !== null
            && WorkflowDefinition::where('tenant_id', $tenantId)->where('code', $workflowCode)->where('is_active', true)->exists();
    }

    /**
     * Starts the approval workflow and locks the subject until the decision.
     *
     * @param  array<string, mixed>  $context
     */
    private function startApproval(string $workflowCode, Model&LockableByWorkflowInterface $approvable, array $context, ?string $actorUserId): void
    {
        if ($this->workflowEngine === null) {
            return;
        }

        $tenantId = (string) $approvable->getAttribute('tenant_id');
        $instance = $this->workflowEngine->start(new StartWorkflowDTO(
            workflowCode: $workflowCode,
            approvable: $approvable,
            contextSnapshot: $context,
            tenantId: $tenantId,
            companyId: $tenantId,
            initiatorUserId: $this->actorUuid($actorUserId)
        ));

        $approvable->lockByWorkflow($instance);
    }

    /**
     * The asset of the active tenant with its row lock.
     */
    private function lockAsset(string $assetId): FixedAsset
    {
        return FixedAsset::where('id', $assetId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Refuses operations on disposed or retired assets.
     */
    private function assertOpen(FixedAsset $asset): void
    {
        if (in_array($asset->status, self::ClosedStatuses, true)) {
            throw new AssetOperationException("Asset [{$asset->asset_number}] is disposed or retired (status: {$asset->status}).");
        }
    }

    /**
     * Refuses operations that need a capitalized asset.
     */
    private function assertCapitalized(FixedAsset $asset): void
    {
        if (! in_array($asset->status, self::CapitalizedStatuses, true)) {
            throw new AssetOperationException("Asset [{$asset->asset_number}] is not capitalized yet (status: {$asset->status}).");
        }
    }

    /**
     * Records an audit ledger entry of the module.
     *
     * @param  array<string, mixed>|null  $before
     */
    private function audit(string $action, Model $model, ?array $before): void
    {
        $this->auditLedger->record(
            action: $action,
            model: $model,
            payloadBefore: $before,
            payloadAfter: $model->fresh()?->toArray() ?? $model->toArray(),
            moduleCode: 'MODULE_16_EAM'
        );
    }

    /**
     * The actor id when it is a valid uuid, else null.
     */
    private function actorUuid(?string $actorUserId): ?string
    {
        return ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;
    }
}
