<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\DisposeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\DTOs\TransferAssetDTO;
use Modules\AssetManagement\DTOs\ValuateAssetDTO;
use Modules\AssetManagement\Models\AssetAssignment;
use Modules\AssetManagement\Models\AssetComponent;
use Modules\AssetManagement\Models\AssetCustodian;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetTransfer;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * Lifecycle of a fixed asset: registration (also from a purchase document), capitalization, commissioning, transfers,
 * revaluation and impairment, partial or total disposal, components, custody and assignments. Operations above the
 * approval thresholds wait for the approval workflow of the tenant, when one is defined, before taking effect.
 */
interface AssetLifecycleServiceInterface
{
    /**
     * Registers a new asset (status registered, nothing posted).
     */
    public function registerAsset(RegisterAssetDTO $dto, ?string $actorUserId = null): FixedAsset;

    /**
     * Registers an asset from a passive invoice line or a purchase order item, each usable once.
     *
     * @param  array<string, mixed>  $attributes  category_id plus optional overrides (name, location_id, ...)
     */
    public function registerFromPurchaseDocument(string $tenantId, string $sourceType, string $sourceId, array $attributes, ?string $actorUserId = null): FixedAsset;

    /**
     * Capitalizes the asset, opens its statutory and tax books and posts the capitalization.
     */
    public function capitalizeAsset(CapitalizeAssetDTO $dto, ?string $actorUserId = null): FixedAsset;

    /**
     * Puts a capitalized asset in service.
     */
    public function commissionAsset(FixedAsset $asset, ?string $commissioningDate = null, ?string $actorUserId = null): FixedAsset;

    /**
     * Moves the asset to another location, custodian, project or cost centre.
     */
    public function transferAsset(TransferAssetDTO $dto, ?string $actorUserId = null): AssetTransfer;

    /**
     * Revalues or impairs the statutory book of the asset.
     */
    public function valuateAsset(ValuateAssetDTO $dto, ?string $actorUserId = null): AssetValuation;

    /**
     * Reverses accumulated impairment losses up to the new recoverable amount.
     */
    public function reverseImpairment(FixedAsset $asset, string|int|float $newRecoverableAmount, ?string $reason = null, ?string $actorUserId = null): AssetValuation;

    /**
     * Disposes of the asset or of a percentage of it, with gain or loss.
     */
    public function disposeAsset(DisposeAssetDTO $dto, ?string $actorUserId = null): AssetDisposal;

    /**
     * Applies an operation once its approval workflow is approved. Returns false when the instance is not ours.
     */
    public function approveWorkflowSubject(string $workflowInstanceId): bool;

    /**
     * Discards an operation whose approval workflow was rejected. Returns false when the instance is not ours.
     */
    public function rejectWorkflowSubject(string $workflowInstanceId): bool;

    /**
     * Adds a component to the asset.
     *
     * @param  array<string, mixed>  $componentData
     */
    public function attachComponent(FixedAsset $parentAsset, array $componentData, ?string $actorUserId = null): AssetComponent;

    /**
     * Marks a component replaced or detached.
     */
    public function retireComponent(FixedAsset $parentAsset, string $componentId, string $status = 'replaced', ?string $actorUserId = null): AssetComponent;

    /**
     * Gives the asset to a custodian, returning the previous custody.
     */
    public function assignCustodian(FixedAsset $asset, string $custodianUserId, ?string $department = null, ?string $actorUserId = null): AssetCustodian;

    /**
     * Assigns the asset to an employee, department, project or site.
     *
     * @param  array<string, mixed>  $assignmentData
     */
    public function assignAsset(FixedAsset $asset, array $assignmentData, ?string $actorUserId = null): AssetAssignment;

    /**
     * Updates the descriptive, warranty and insurance data of the asset (not its values).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateAsset(FixedAsset $asset, array $attributes, ?string $actorUserId = null): FixedAsset;

    /**
     * Deletes an asset that was never capitalized.
     */
    public function deleteAsset(FixedAsset $asset, ?string $actorUserId = null): void;
}
