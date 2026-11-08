<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AssetManagement\Contracts\AssetAccountingServiceInterface;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Events\AssetDepreciationCalculatedEvent;
use Modules\AssetManagement\Exceptions\AssetOperationException;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Support\AssetLedger;
use Modules\AssetManagement\Support\DepreciationPeriod;
use Modules\AssetManagement\Support\Money;

/**
 * Depreciation runs over the parallel books. One run per tenant, period, period type and ledger (unique index); each
 * asset book is charged at most once per period, in chronological order, and only the statutory book posts to the
 * General Ledger. Runs are started manually by the accountant (no schedule).
 */
final readonly class DepreciationEngineService implements DepreciationEngineServiceInterface
{
    /** Asset statuses that depreciate; assets under maintenance keep depreciating. */
    public const array DepreciableStatuses = ['capitalized', 'commissioned', 'in_service', 'active', 'revalued', 'impaired', 'under_maintenance'];

    public function __construct(
        private AssetAccountingServiceInterface $accountingService,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private AssetBookService $books,
        private DepreciationCalculator $calculator,
    ) {}

    public function calculateAssetDepreciationAmount(FixedAsset $asset, string $periodType = 'monthly', string|int|float $unitsProduced = 0): string
    {
        if (! $asset->is_depreciable || $asset->is_fully_depreciated) {
            return '0.00';
        }

        $book = $this->books->bookFor($asset, AssetLedger::Statutory, false);
        $period = DepreciationPeriod::containing(CarbonImmutable::today(), $this->periodType($periodType));
        $book->pro_rata_convention = 'full';
        $book->depreciation_start_date ??= $period->start;

        return $this->calculator->periodCharge($book, $period, $unitsProduced);
    }

    public function executeDepreciationRun(RunDepreciationDTO $dto, ?string $actorUserId = null): DepreciationRun
    {
        $ledgerType = AssetLedger::normalize($dto->ledgerType);
        $period = $this->period($dto->periodCode, $dto->periodType);
        $postsToGl = $dto->autoPostToGl && $ledgerType === AssetLedger::Statutory;

        return DB::transaction(function () use ($dto, $actorUserId, $ledgerType, $period, $postsToGl) {
            $alreadyExecuted = new AssetOperationException("Depreciation run already executed for period [{$dto->periodCode}] and ledger [{$ledgerType}].", 'period_code');

            $exists = DepreciationRun::where('tenant_id', $dto->tenantId)
                ->where('period_code', $dto->periodCode)
                ->where('period_type', $period->type)
                ->where('ledger_type', $ledgerType)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw $alreadyExecuted;
            }

            $this->assertNoOverlappingRun($dto->tenantId, $ledgerType, $period);
            $actorUuid = $this->actor($actorUserId);

            // The lock above cannot cover a row that does not exist yet: two concurrent runs of the same period both get
            // here, and the unique index on (tenant, period, period type, ledger) lets only one insert through.
            try {
                $run = DB::transaction(fn (): DepreciationRun => DepreciationRun::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $dto->tenantId,
                    'company_id' => $dto->companyId,
                    'run_number' => 'DEPR-RUN-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                    'period_code' => $dto->periodCode,
                    'period_type' => $period->type,
                    'ledger_type' => $ledgerType,
                    'assets_processed_count' => 0,
                    'total_depreciation_amount' => '0.00',
                    'status' => 'running',
                    'executed_by_user_id' => $actorUuid,
                    'executed_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                throw $alreadyExecuted;
            }

            $processed = 0;
            $total = '0';
            $skippedLocked = [];
            $skippedAlreadyDepreciated = 0;

            $this->depreciableAssets($dto->tenantId, $dto->singleAssetId)->lazyById(200)->each(
                function (FixedAsset $asset) use ($dto, $period, $ledgerType, $run, $postsToGl, $actorUuid, &$processed, &$total, &$skippedLocked, &$skippedAlreadyDepreciated): void {
                    if ($asset->is_locked) {
                        $skippedLocked[] = (string) $asset->asset_number;

                        return;
                    }

                    if ($this->latestScheduledPeriodEnd($asset, $ledgerType)?->gte($period->start)) {
                        $skippedAlreadyDepreciated++;

                        return;
                    }

                    $schedule = $this->calculateSingleAssetDepreciation(
                        asset: $asset,
                        periodCode: $period->code,
                        periodType: $period->type,
                        ledgerType: $ledgerType,
                        unitsProduced: $dto->unitsProducedByAssetId[(string) $asset->id] ?? 0,
                        runId: (string) $run->id,
                        autoPostToGl: $postsToGl,
                        actorUserId: $actorUuid
                    );

                    if (Money::isPositive($schedule->depreciation_amount)) {
                        $processed++;
                        $total = Money::add($total, $schedule->depreciation_amount);
                    }
                }
            );

            $total = Money::round($total);

            $run->update([
                'assets_processed_count' => $processed,
                'total_depreciation_amount' => $total,
                'status' => $postsToGl ? 'posted' : 'calculated',
                'posted_at' => $postsToGl ? now() : null,
                'summary_metrics' => [
                    'period_code' => $period->code,
                    'period_type' => $period->type,
                    'ledger_type' => $ledgerType,
                    'period_start' => $period->start->toDateString(),
                    'period_end' => $period->end->toDateString(),
                    'assets_processed' => $processed,
                    'total_depreciation' => $total,
                    'skipped_locked_assets' => $skippedLocked,
                    'skipped_already_depreciated' => $skippedAlreadyDepreciated,
                ],
            ]);

            $this->auditLedger->record(
                action: 'DEPRECIATION_RUN_EXECUTED',
                model: $run,
                payloadBefore: null,
                payloadAfter: $run->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            return $run->fresh();
        });
    }

    public function calculateSingleAssetDepreciation(
        FixedAsset $asset,
        string $periodCode,
        string $periodType = 'monthly',
        string $ledgerType = 'statutory',
        string|int|float $unitsProduced = 0,
        ?string $runId = null,
        bool $autoPostToGl = true,
        ?string $actorUserId = null
    ): DepreciationSchedule {
        $ledgerType = AssetLedger::normalize($ledgerType);
        $period = $this->period($periodCode, $periodType);

        return DB::transaction(function () use ($asset, $period, $ledgerType, $unitsProduced, $runId, $autoPostToGl, $actorUserId) {
            $lockedAsset = FixedAsset::where('tenant_id', $asset->tenant_id)->where('id', $asset->id)->lockForUpdate()->firstOrFail();

            $duplicate = DepreciationSchedule::where('tenant_id', $lockedAsset->tenant_id)
                ->where('asset_id', $lockedAsset->id)
                ->where('ledger_type', $ledgerType)
                ->where('period_code', $period->code)
                ->exists();

            if ($duplicate) {
                throw new AssetOperationException("Duplicate depreciation prevented for asset [{$lockedAsset->asset_number}] in period [{$period->code}].", 'period_code');
            }

            if ($this->latestScheduledPeriodEnd($lockedAsset, $ledgerType)?->gte($period->start)) {
                throw new AssetOperationException("Asset [{$lockedAsset->asset_number}] is already depreciated in the [{$ledgerType}] ledger for a period overlapping or following [{$period->code}].", 'period_code');
            }

            $before = $lockedAsset->toArray();
            $book = $this->books->bookFor($lockedAsset, $ledgerType);
            $charge = $lockedAsset->is_depreciable && ! $book->is_fully_depreciated
                ? $this->calculator->periodCharge($book, $period, $unitsProduced)
                : '0.00';

            $opening = Money::round($book->net_book_value);
            $closing = Money::round(Money::sub($opening, $charge));
            $accumulated = Money::round(Money::add($book->accumulated_depreciation, $charge));
            $fullyDepreciated = Money::compare($closing, $book->salvage_value) <= 0 && $book->depreciation_start_date !== null;

            $schedule = DepreciationSchedule::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $lockedAsset->tenant_id,
                'asset_id' => $lockedAsset->id,
                'run_id' => $runId,
                'ledger_type' => $ledgerType,
                'period_code' => $period->code,
                'period_type' => $period->type,
                'depreciation_method' => $book->depreciation_method,
                'opening_book_value' => $opening,
                'depreciation_amount' => $charge,
                'closing_book_value' => $closing,
                'accumulated_depreciation' => $accumulated,
                'units_produced' => Money::round($unitsProduced),
                'status' => 'calculated',
                'calculated_at' => now(),
                'calculation_snapshot' => [
                    'method' => $book->depreciation_method,
                    'useful_life_months' => $book->useful_life_months,
                    'rate_percent' => $book->rate_percent,
                    'declining_balance_factor' => $book->declining_balance_factor,
                    'pro_rata_convention' => $book->pro_rata_convention,
                    'depreciation_start_date' => $book->depreciation_start_date?->toDateString(),
                    'depreciable_base' => $book->depreciable_base,
                    'salvage_value' => $book->salvage_value,
                ],
            ]);

            $book->update([
                'accumulated_depreciation' => $accumulated,
                'net_book_value' => $closing,
                'consumed_units' => Money::round(Money::add($book->consumed_units, $unitsProduced)),
                'last_period_code' => $period->code,
                'is_fully_depreciated' => $fullyDepreciated,
            ]);

            if ($ledgerType === AssetLedger::Statutory) {
                $this->books->applyToMaster($book, $lockedAsset);
                $lockedAsset->last_depreciation_date = Carbon::parse($period->end->toDateString());
                $lockedAsset->save();
            }

            $this->auditLedger->record(
                action: 'ASSET_DEPRECIATION_CALCULATED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: ['ledger_type' => $ledgerType, 'period_code' => $period->code, 'depreciation_amount' => $charge, 'closing_book_value' => $closing],
                moduleCode: 'MODULE_16_EAM'
            );

            $this->dispatcher->publishAndDispatch(new AssetDepreciationCalculatedEvent(
                scheduleId: (string) $schedule->id,
                assetId: (string) $lockedAsset->id,
                runId: $runId,
                periodCode: $period->code,
                ledgerType: $ledgerType,
                openingBookValue: Money::toFloat($opening),
                depreciationAmount: Money::toFloat($charge),
                closingBookValue: Money::toFloat($closing),
                tenantId: (string) $lockedAsset->tenant_id,
                companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
            ));

            if ($autoPostToGl && $book->posts_to_gl && Money::isPositive($charge)) {
                $this->accountingService->postDepreciationEntry($schedule, $lockedAsset, $actorUserId);
            }

            return $schedule->fresh();
        });
    }

    public function previewSchedule(FixedAsset $asset, string $ledgerType = 'statutory', string $periodType = 'yearly', int $maxPeriods = 60, string|int|float $unitsPerPeriod = 0): array
    {
        $book = $this->books->bookFor($asset, $ledgerType, false);

        if ($book->is_fully_depreciated || $book->depreciation_start_date === null) {
            return [];
        }

        $type = $this->periodType($periodType);
        $lastEnd = $book->exists ? $this->latestScheduledPeriodEnd($asset, AssetLedger::normalize($ledgerType)) : null;
        $from = $lastEnd !== null
            ? DepreciationPeriod::containing($lastEnd->addDay(), $type)
            : DepreciationPeriod::containing($book->depreciation_start_date, $type);

        return $this->calculator->project($book, $from, max(1, $maxPeriods), $unitsPerPeriod);
    }

    public function simulateRun(RunDepreciationDTO $dto, ?string $categoryId = null): array
    {
        $ledgerType = AssetLedger::normalize($dto->ledgerType);
        $period = $this->period($dto->periodCode, $dto->periodType);
        $lines = [];
        $total = '0';

        $query = $this->depreciableAssets($dto->tenantId, $dto->singleAssetId)->where('is_locked', false);
        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        $query->lazyById(200)->each(function (FixedAsset $asset) use ($dto, $period, $ledgerType, &$lines, &$total): void {
            if ($this->latestScheduledPeriodEnd($asset, $ledgerType)?->gte($period->start)) {
                return;
            }

            $book = $this->books->bookFor($asset, $ledgerType, false);
            $charge = $book->is_fully_depreciated ? '0.00' : $this->calculator->periodCharge($book, $period, $dto->unitsProducedByAssetId[(string) $asset->id] ?? 0);

            if (! Money::isPositive($charge)) {
                return;
            }

            $total = Money::add($total, $charge);
            $lines[] = [
                'asset_id' => (string) $asset->id,
                'asset_number' => (string) $asset->asset_number,
                'name' => (string) $asset->name,
                'depreciation_method' => (string) $book->depreciation_method,
                'opening_book_value' => Money::round($book->net_book_value),
                'depreciation_amount' => $charge,
                'closing_book_value' => Money::round(Money::sub($book->net_book_value, $charge)),
            ];
        });

        return [
            'period_code' => $period->code,
            'period_type' => $period->type,
            'ledger_type' => $ledgerType,
            'assets_count' => count($lines),
            'total_depreciation_amount' => Money::round($total),
            'lines' => $lines,
        ];
    }

    /**
     * Depreciable assets of the tenant, optionally a single one.
     *
     * @return Builder<FixedAsset>
     */
    private function depreciableAssets(string $tenantId, ?string $singleAssetId): Builder
    {
        $query = FixedAsset::where('tenant_id', $tenantId)
            ->whereIn('status', self::DepreciableStatuses)
            ->where('is_depreciable', true)
            ->where('is_fully_depreciated', false);

        if ($singleAssetId !== null) {
            $query->where('id', $singleAssetId);
        }

        return $query;
    }

    /**
     * End date of the latest period already charged to the asset book, null when none.
     */
    private function latestScheduledPeriodEnd(FixedAsset $asset, string $ledgerType): ?CarbonImmutable
    {
        $latest = null;

        DepreciationSchedule::where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->id)
            ->where('ledger_type', $ledgerType)
            ->get(['period_code', 'period_type'])
            ->each(function (DepreciationSchedule $schedule) use (&$latest): void {
                try {
                    $end = DepreciationPeriod::fromCode((string) $schedule->period_code, (string) $schedule->period_type)->end;
                } catch (InvalidArgumentException) {
                    return;
                }

                if ($latest === null || $end->gt($latest)) {
                    $latest = $end;
                }
            });

        return $latest;
    }

    /**
     * Refuses a run whose dates overlap a run of another period type in the same ledger (monthly and yearly runs of
     * the same months would charge the assets twice).
     */
    private function assertNoOverlappingRun(string $tenantId, string $ledgerType, DepreciationPeriod $period): void
    {
        DepreciationRun::where('tenant_id', $tenantId)
            ->where('ledger_type', $ledgerType)
            ->where('period_type', '!=', $period->type)
            ->where('period_code', 'like', $period->start->format('Y').'%')
            ->get(['period_code', 'period_type'])
            ->each(function (DepreciationRun $run) use ($period): void {
                try {
                    $other = DepreciationPeriod::fromCode((string) $run->period_code, (string) $run->period_type);
                } catch (InvalidArgumentException) {
                    return;
                }

                if ($other->start->lte($period->end) && $other->end->gte($period->start)) {
                    throw new AssetOperationException("A {$run->period_type} run [{$run->period_code}] already covers part of period [{$period->code}].", 'period_code');
                }
            });
    }

    /**
     * Parses the period, as a 422 when the code does not match its type.
     */
    private function period(string $code, string $type): DepreciationPeriod
    {
        try {
            return DepreciationPeriod::fromCode($code, $this->periodType($type));
        } catch (InvalidArgumentException $exception) {
            throw new AssetOperationException($exception->getMessage(), 'period_code');
        }
    }

    /**
     * A supported period type, as a 422 otherwise.
     */
    private function periodType(string $type): string
    {
        if (! in_array($type, DepreciationPeriod::Types, true)) {
            throw new AssetOperationException("Unsupported period type [{$type}].", 'period_type');
        }

        return $type;
    }

    /**
     * The acting user when it is an existing user, else null (system runs).
     */
    private function actor(?string $actorUserId): ?string
    {
        return ! empty($actorUserId) && Str::isUuid($actorUserId) && User::whereKey($actorUserId)->exists() ? $actorUserId : null;
    }
}
