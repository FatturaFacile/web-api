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

interface AssetLifecycleServiceInterface
{
    public function registerAsset(RegisterAssetDTO $dto, ?string $actorUserId = null): FixedAsset;

    public function capitalizeAsset(CapitalizeAssetDTO $dto, ?string $actorUserId = null): FixedAsset;

    public function commissionAsset(FixedAsset $asset, ?string $commissioningDate = null, ?string $actorUserId = null): FixedAsset;

    public function transferAsset(TransferAssetDTO $dto, ?string $actorUserId = null): AssetTransfer;

    public function valuateAsset(ValuateAssetDTO $dto, ?string $actorUserId = null): AssetValuation;

    public function reverseImpairment(FixedAsset $asset, float $newRecoverableAmount, ?string $reason = null, ?string $actorUserId = null): AssetValuation;

    public function disposeAsset(DisposeAssetDTO $dto, ?string $actorUserId = null): AssetDisposal;

    public function attachComponent(FixedAsset $parentAsset, array $componentData, ?string $actorUserId = null): AssetComponent;

    public function assignCustodian(FixedAsset $asset, string $custodianUserId, ?string $department = null, ?string $actorUserId = null): AssetCustodian;

    public function assignAsset(FixedAsset $asset, array $assignmentData, ?string $actorUserId = null): AssetAssignment;
}
