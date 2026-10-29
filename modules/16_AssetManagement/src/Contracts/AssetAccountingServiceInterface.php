<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\GeneralLedger\Models\JournalEntry;

interface AssetAccountingServiceInterface
{
    public function postCapitalizationEntry(FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    public function postDepreciationEntry(DepreciationSchedule $schedule, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    public function postImpairmentEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    public function postRevaluationEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    public function postDisposalEntry(AssetDisposal $disposal, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;
}
