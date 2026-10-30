<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AssetManagement\Contracts\AssetAccountingServiceInterface;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Events\AssetDepreciationCalculatedEvent;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;

final readonly class DepreciationEngineService implements DepreciationEngineServiceInterface
{
    public function __construct(
        private AssetAccountingServiceInterface $accountingService,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function calculateAssetDepreciationAmount(
        FixedAsset $asset,
        string $periodType = 'monthly',
        float $unitsProduced = 0.0
    ): float {
        if (! $asset->is_depreciable || $asset->is_fully_depreciated) {
            return 0.0;
        }

        $carryingAmount = (float) $asset->carrying_amount;
        $salvageValue = (float) $asset->salvage_value;
        $maxDepreciableRemaining = max(0.0, round($carryingAmount - $salvageValue, 2));

        if ($maxDepreciableRemaining <= 0.0) {
            return 0.0;
        }

        $depreciableBase = (float) $asset->depreciable_base > 0
            ? (float) $asset->depreciable_base
            : max(0.0, (float) $asset->capitalized_cost - $salvageValue);

        $lifeYears = max(1, (int) $asset->useful_life_years);
        $lifeMonths = max(1, (int) $asset->useful_life_months > 0 ? (int) $asset->useful_life_months : $lifeYears * 12);
        $ratePercent = (float) $asset->depreciation_rate_percent > 0
            ? (float) $asset->depreciation_rate_percent
            : round(100.0 / $lifeYears, 2);

        $method = $asset->depreciation_method ?? 'straight_line';
        $rawAmount = 0.0;

        switch ($method) {
            case 'straight_line':
                $rawAmount = $periodType === 'yearly'
                    ? $depreciableBase / $lifeYears
                    : $depreciableBase / $lifeMonths;
                break;

            case 'declining_balance':
                $yearlyAmount = $carryingAmount * ($ratePercent / 100.0);
                $rawAmount = $periodType === 'yearly' ? $yearlyAmount : $yearlyAmount / 12.0;
                break;

            case 'double_declining_balance':
                $ddbRate = 2.0 / $lifeYears;
                $yearlyAmount = $carryingAmount * $ddbRate;
                $rawAmount = $periodType === 'yearly' ? $yearlyAmount : $yearlyAmount / 12.0;
                break;

            case 'units_of_production':
                $totalExpectedUnits = (float) $asset->total_expected_units;
                if ($totalExpectedUnits <= 0.0 || $unitsProduced <= 0.0) {
                    $rawAmount = 0.0;
                } else {
                    $costPerUnit = $depreciableBase / $totalExpectedUnits;
                    $rawAmount = $costPerUnit * $unitsProduced;
                }
                break;

            case 'custom':
            default:
                $specs = is_array($asset->specifications) ? $asset->specifications : [];
                $customFactor = isset($specs['custom_period_rate_percent'])
                    ? (float) $specs['custom_period_rate_percent'] / 100.0
                    : ($ratePercent / 100.0) / ($periodType === 'yearly' ? 1.0 : 12.0);
                $rawAmount = $depreciableBase * $customFactor;
                break;
        }

        return round(min($maxDepreciableRemaining, max(0.0, $rawAmount)), 2);
    }

    public function executeDepreciationRun(RunDepreciationDTO $dto, ?string $actorUserId = null): DepreciationRun
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $existingRun = DepreciationRun::where('tenant_id', $dto->tenantId)
                ->where('period_code', $dto->periodCode)
                ->where('period_type', $dto->periodType)
                ->where('ledger_type', $dto->ledgerType)
                ->lockForUpdate()
                ->first();

            if ($existingRun !== null) {
                throw new InvalidArgumentException("Depreciation run already executed for period [{$dto->periodCode}] and ledger [{$dto->ledgerType}].");
            }

            $actorUuid = (! empty($actorUserId) && Str::isUuid($actorUserId) && User::where('id', $actorUserId)->exists()) ? $actorUserId : null;

            $run = DepreciationRun::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'run_number' => 'DEPR-RUN-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'period_code' => $dto->periodCode,
                'period_type' => $dto->periodType,
                'ledger_type' => $dto->ledgerType,
                'assets_processed_count' => 0,
                'total_depreciation_amount' => 0.00,
                'status' => 'running',
                'executed_by_user_id' => $actorUuid,
                'executed_at' => now(),
            ]);

            $query = FixedAsset::where('tenant_id', $dto->tenantId)
                ->whereIn('status', ['capitalized', 'commissioned', 'in_service', 'active', 'revalued', 'impaired'])
                ->where('is_depreciable', true)
                ->where('is_fully_depreciated', false)
                ->where('is_locked', false);

            if ($dto->singleAssetId !== null) {
                $query->where('id', $dto->singleAssetId);
            }

            // Use SKIP LOCKED on PostgreSQL to prevent worker contention
            if (DB::connection()->getDriverName() === 'pgsql') {
                $assets = $query->lock('FOR UPDATE SKIP LOCKED')->get();
            } else {
                $assets = $query->lockForUpdate()->get();
            }

            $processedCount = 0;
            $totalDepreciation = 0.0;

            foreach ($assets as $asset) {
                // Skip if already depreciated for this period/ledger
                $alreadyExists = DepreciationSchedule::where('tenant_id', $dto->tenantId)
                    ->where('asset_id', $asset->id)
                    ->where('ledger_type', $dto->ledgerType)
                    ->where('period_code', $dto->periodCode)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $units = (float) ($dto->unitsProducedByAssetId[$asset->id] ?? 0.0);

                $schedule = $this->calculateSingleAssetDepreciation(
                    asset: $asset,
                    periodCode: $dto->periodCode,
                    periodType: $dto->periodType,
                    ledgerType: $dto->ledgerType,
                    unitsProduced: $units,
                    runId: (string) $run->id,
                    autoPostToGl: $dto->autoPostToGl,
                    actorUserId: $actorUuid
                );

                if ((float) $schedule->depreciation_amount > 0.0) {
                    $processedCount++;
                    $totalDepreciation = round($totalDepreciation + (float) $schedule->depreciation_amount, 2);
                }
            }

            $run->update([
                'assets_processed_count' => $processedCount,
                'total_depreciation_amount' => $totalDepreciation,
                'status' => $dto->autoPostToGl ? 'posted' : 'calculated',
                'posted_at' => $dto->autoPostToGl ? now() : null,
                'summary_metrics' => [
                    'period_code' => $dto->periodCode,
                    'period_type' => $dto->periodType,
                    'ledger_type' => $dto->ledgerType,
                    'assets_processed' => $processedCount,
                    'total_depreciation' => $totalDepreciation,
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
        float $unitsProduced = 0.0,
        ?string $runId = null,
        bool $autoPostToGl = true,
        ?string $actorUserId = null
    ): DepreciationSchedule {
        return DB::transaction(function () use (
            $asset,
            $periodCode,
            $periodType,
            $ledgerType,
            $unitsProduced,
            $runId,
            $autoPostToGl,
            $actorUserId
        ) {
            $lockedAsset = FixedAsset::where('id', $asset->id)->lockForUpdate()->firstOrFail();

            $duplicate = DepreciationSchedule::where('tenant_id', $lockedAsset->tenant_id)
                ->where('asset_id', $lockedAsset->id)
                ->where('ledger_type', $ledgerType)
                ->where('period_code', $periodCode)
                ->exists();

            if ($duplicate) {
                throw new InvalidArgumentException("Duplicate depreciation prevented for asset [{$lockedAsset->asset_number}] in period [{$periodCode}].");
            }

            $before = $lockedAsset->toArray();
            $openingBookValue = (float) $lockedAsset->carrying_amount;
            $depreciationAmount = $this->calculateAssetDepreciationAmount($lockedAsset, $periodType, $unitsProduced);
            $closingBookValue = round(max((float) $lockedAsset->salvage_value, $openingBookValue - $depreciationAmount), 2);
            $newAccumulated = round((float) $lockedAsset->accumulated_depreciation + $depreciationAmount, 2);
            $newConsumedUnits = round((float) $lockedAsset->consumed_units + $unitsProduced, 2);
            $isFullyDepreciated = $closingBookValue <= (float) $lockedAsset->salvage_value + 0.001;

            $schedule = DepreciationSchedule::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $lockedAsset->tenant_id,
                'asset_id' => $lockedAsset->id,
                'run_id' => $runId,
                'ledger_type' => $ledgerType,
                'period_code' => $periodCode,
                'period_type' => $periodType,
                'depreciation_method' => $lockedAsset->depreciation_method,
                'opening_book_value' => $openingBookValue,
                'depreciation_amount' => $depreciationAmount,
                'closing_book_value' => $closingBookValue,
                'accumulated_depreciation' => $newAccumulated,
                'units_produced' => $unitsProduced,
                'status' => 'calculated',
                'calculated_at' => now(),
                'calculation_snapshot' => [
                    'method' => $lockedAsset->depreciation_method,
                    'useful_life_years' => $lockedAsset->useful_life_years,
                    'salvage_value' => (float) $lockedAsset->salvage_value,
                    'rate_percent' => (float) $lockedAsset->depreciation_rate_percent,
                ],
            ]);

            if ($ledgerType === 'statutory') {
                $lockedAsset->update([
                    'accumulated_depreciation' => $newAccumulated,
                    'carrying_amount' => $closingBookValue,
                    'consumed_units' => $newConsumedUnits,
                    'last_depreciation_date' => date('Y-m-d'),
                    'is_fully_depreciated' => $isFullyDepreciated,
                ]);
            }

            $this->auditLedger->record(
                action: 'ASSET_DEPRECIATION_CALCULATED',
                model: $lockedAsset,
                payloadBefore: $before,
                payloadAfter: $lockedAsset->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $calcEvent = new AssetDepreciationCalculatedEvent(
                scheduleId: (string) $schedule->id,
                assetId: (string) $lockedAsset->id,
                runId: $runId,
                periodCode: $periodCode,
                ledgerType: $ledgerType,
                openingBookValue: $openingBookValue,
                depreciationAmount: $depreciationAmount,
                closingBookValue: $closingBookValue,
                tenantId: (string) $lockedAsset->tenant_id,
                companyId: (string) ($lockedAsset->company_id ?? $lockedAsset->tenant_id)
            );
            $this->dispatcher->publish($calcEvent);
            event($calcEvent);

            if ($autoPostToGl && $depreciationAmount > 0.0) {
                $this->accountingService->postDepreciationEntry($schedule, $lockedAsset, $actorUserId);
            }

            return $schedule->fresh();
        });
    }
}
