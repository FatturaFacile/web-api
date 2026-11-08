<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Contracts;

use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Journal entries of the asset lifecycle, posted only through the General Ledger posting service and dated on the
 * document (capitalization, period end, valuation, disposal). Each method returns null when there is nothing to post.
 */
interface AssetAccountingServiceInterface
{
    /**
     * Dr fixed asset / Cr acquisition clearing (or the cost account of the source invoice line).
     */
    public function postCapitalizationEntry(FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    /**
     * Dr depreciation expense / Cr accumulated depreciation, for books that post to the ledger.
     */
    public function postDepreciationEntry(DepreciationSchedule $schedule, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    /**
     * Dr impairment loss / Cr fixed asset.
     */
    public function postImpairmentEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    /**
     * Revaluation split between the revaluation reserve and profit or loss.
     */
    public function postRevaluationEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    /**
     * Dr fixed asset / Cr impairment loss (reversal through profit or loss).
     */
    public function postImpairmentReversalEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    /**
     * Derecognition of the disposed share with gain or loss.
     */
    public function postDisposalEntry(AssetDisposal $disposal, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry;

    /**
     * Dr fixed asset / Cr acquisition clearing for maintenance capitalized as an improvement.
     */
    public function postImprovementEntry(MaintenanceWorkOrder $workOrder, FixedAsset $asset, string $amount, ?string $actorUserId = null): ?JournalEntry;
}
