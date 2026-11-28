<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Listeners;

use Modules\AssetManagement\Events\AssetDepreciationPostedEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetImpairedEvent;
use Modules\AssetManagement\Events\AssetRegisteredEvent;
use Modules\AssetManagement\Events\AssetRevaluedEvent;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\Amount;

/**
 * Fixed assets: acquisitions, depreciation runs, revaluations, impairments and disposals. The valid time of a depreciation is the end of
 * its period (the posting date of its journal entry), of a valuation its valuation date, of a disposal its disposal date.
 */
final class FixedAssetEventCapture
{
    use CapturesDomainEvents;

    public function onRegistered(AssetRegisteredEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $this->recorder->exists($event->tenantId, AggregateType::FixedAssets, $event->assetId, ['AssetAcquired'])) {
            return;
        }

        $this->record($event->tenantId, AggregateType::FixedAssets, $event->assetId, 'AssetAcquired', $this->validAtOf($event->getOccurredAt()), [
            'asset_id' => $event->assetId,
            'asset_number' => $event->assetNumber,
            'name' => $event->name,
            'category_id' => $event->categoryId,
            'acquisition_type' => $event->acquisitionType,
            'cost' => Amount::of($event->acquisitionCost),
        ], 'asset_management.asset.registered');
    }

    public function onDepreciationPosted(AssetDepreciationPostedEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $this->recorder->exists($event->tenantId, AggregateType::FixedAssets, $event->assetId, ['AssetDepreciated']) && $this->alreadyDepreciated($event)) {
            return;
        }

        $postingDate = JournalEntry::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->journalEntryId)->value('posting_date');

        $this->record($event->tenantId, AggregateType::FixedAssets, $event->assetId, 'AssetDepreciated', $this->validAtOf($postingDate !== null ? substr((string) $postingDate, 0, 10) : $event->getOccurredAt()), [
            'asset_id' => $event->assetId,
            'schedule_id' => $event->scheduleId,
            'journal_entry_id' => $event->journalEntryId,
            'period_code' => $event->periodCode,
            'depreciation_amount' => Amount::of($event->depreciationAmount),
            'new_carrying_amount' => Amount::of($event->newCarryingAmount),
        ], 'asset_management.depreciation.posted');
    }

    public function onRevalued(AssetRevaluedEvent $event): void
    {
        $this->adjust($event->tenantId, $event->assetId, 'AssetRevalued', $event->valuationDate, $event->carryingAmountBefore, $event->carryingAmountAfter, $event->valuationId, 'asset_management.asset.revalued');
    }

    public function onImpaired(AssetImpairedEvent $event): void
    {
        $this->adjust($event->tenantId, $event->assetId, 'AssetImpaired', $event->valuationDate, $event->carryingAmountBefore, $event->carryingAmountAfter, $event->valuationId, 'asset_management.asset.impaired');
    }

    public function onDisposed(AssetDisposedEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $this->recorder->exists($event->tenantId, AggregateType::FixedAssets, $event->assetId, ['AssetDisposed'])) {
            return;
        }

        $this->record($event->tenantId, AggregateType::FixedAssets, $event->assetId, 'AssetDisposed', $this->validAtOf($event->disposalDate), [
            'asset_id' => $event->assetId,
            'disposal_id' => $event->disposalId,
            'disposal_type' => $event->disposalType,
            'cost' => Amount::of($event->assetCost),
            'accumulated_depreciation' => Amount::of($event->accumulatedDepreciation),
            'carrying_amount' => Amount::of($event->carryingAmount),
            'proceeds' => Amount::of($event->proceedsAmount),
            'gain_loss' => Amount::of($event->gainLossAmount),
        ], 'asset_management.asset.disposed');
    }

    private function adjust(string $tenantId, string $assetId, string $type, string $valuationDate, float $before, float $after, string $valuationId, string $source): void
    {
        if (! $this->enabled($tenantId)) {
            return;
        }

        $this->record($tenantId, AggregateType::FixedAssets, $assetId, $type, $this->validAtOf($valuationDate), [
            'asset_id' => $assetId,
            'valuation_id' => $valuationId,
            'carrying_amount_before' => Amount::of($before),
            'carrying_amount_after' => Amount::of($after),
            'adjustment' => Amount::sub($after, $before),
        ], $source);
    }

    /**
     * A schedule is posted once: the same schedule never depreciates the asset twice.
     */
    private function alreadyDepreciated(AssetDepreciationPostedEvent $event): bool
    {
        return BiTemporalEvent::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('stream_id', AggregateType::FixedAssets->streamId())
            ->where('aggregate_id', $event->assetId)
            ->where('event_type', 'AssetDepreciated')
            ->get(['payload'])
            ->contains(static fn ($recorded): bool => ($recorded->payload['schedule_id'] ?? null) === $event->scheduleId);
    }
}
