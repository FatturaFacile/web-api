<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\PeriodLock;

final readonly class FiduciaryPeriodLockService
{
    private const array SUBLEDGERS = ['GL', 'AR', 'AP', 'INVENTORY', 'PAYROLL', 'FIXED_ASSETS', 'PROJECTS'];

    public function __construct(private FiduciaryAuditService $audit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listPeriods(string $tenantId, ?string $fiscalYearId = null): array
    {
        $periods = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($fiscalYearId !== null, fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))
            ->with('fiscalYear')
            ->orderBy('start_date')
            ->get();

        return $periods->map(function (FiscalPeriod $period): array {
            $entriesCount = JournalEntry::withoutGlobalScopes()
                ->where('tenant_id', $period->tenant_id)
                ->where('fiscal_period_id', $period->id)
                ->count();
            $verifiedCount = JournalEntry::withoutGlobalScopes()
                ->where('tenant_id', $period->tenant_id)
                ->where('fiscal_period_id', $period->id)
                ->where('fiduciary_verified', true)
                ->count();

            return [
                'id' => (string) $period->id,
                'fiscal_year_id' => (string) $period->fiscal_year_id,
                'year_code' => (string) ($period->fiscalYear->year_code ?? ''),
                'period_number' => (int) $period->period_number,
                'period_name' => (string) $period->period_name,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
                'status' => (string) $period->status,
                'is_fiduciary_locked' => $period->isFiduciaryLocked(),
                'fiduciary_locked_at' => $period->fiduciary_locked_at?->toIso8601String(),
                'fiduciary_locked_by' => $period->fiduciary_locked_by ? (string) $period->fiduciary_locked_by : null,
                'fiduciary_lock_reason' => $period->fiduciary_lock_reason,
                'entries_count' => $entriesCount,
                'verified_entries_count' => $verifiedCount,
            ];
        })->values()->all();
    }

    public function lockPeriod(string $tenantId, string $fiscalPeriodId, string $reason, User $actor): FiscalPeriod
    {
        return DB::transaction(function () use ($tenantId, $fiscalPeriodId, $reason, $actor): FiscalPeriod {
            /** @var FiscalPeriod $period */
            $period = FiscalPeriod::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($fiscalPeriodId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($period->status === 'closed') {
                throw new LedgerConflictException("Fiscal period [{$period->period_name}] is already closed.");
            }

            $actorId = (string) $actor->getKey();
            $period->update([
                'status' => 'locked_by_fiduciary',
                'fiduciary_locked_at' => now(),
                'fiduciary_locked_by' => $actorId,
                'fiduciary_lock_reason' => $reason,
            ]);

            foreach (self::SUBLEDGERS as $subledger) {
                PeriodLock::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'fiscal_period_id' => $period->id,
                        'subledger_module' => $subledger,
                    ],
                    [
                        'id' => (string) Str::uuid7(),
                        'is_locked' => true,
                        'locked_by' => $actorId,
                        'locked_at' => now(),
                    ]
                );
            }

            $this->audit->record(
                tenantId: $tenantId,
                action: 'period_locked_by_fiduciary',
                summary: "Locked fiscal period [{$period->period_name}]: {$reason}",
                actor: $actor,
                targetType: 'fiscal_period',
                targetId: (string) $period->id,
                targetReference: (string) $period->period_name,
                metadata: ['reason' => $reason, 'start_date' => $period->start_date?->toDateString(), 'end_date' => $period->end_date?->toDateString()],
                auditModel: $period,
            );

            return $period->refresh();
        });
    }

    public function unlockPeriod(string $tenantId, string $fiscalPeriodId, string $reason, User $actor): FiscalPeriod
    {
        return DB::transaction(function () use ($tenantId, $fiscalPeriodId, $reason, $actor): FiscalPeriod {
            /** @var FiscalPeriod $period */
            $period = FiscalPeriod::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($fiscalPeriodId)
                ->with('fiscalYear')
                ->lockForUpdate()
                ->firstOrFail();

            if ($period->fiscalYear && $period->fiscalYear->status === 'closed') {
                throw new LedgerConflictException("Cannot unlock fiscal period [{$period->period_name}]: parent fiscal year is closed.");
            }

            $period->update([
                'status' => 'open',
                'fiduciary_locked_at' => null,
                'fiduciary_locked_by' => null,
                'fiduciary_lock_reason' => null,
            ]);

            PeriodLock::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('fiscal_period_id', $period->id)
                ->update(['is_locked' => false]);

            $this->audit->record(
                tenantId: $tenantId,
                action: 'period_unlocked_by_fiduciary',
                summary: "Unlocked fiscal period [{$period->period_name}]: {$reason}",
                actor: $actor,
                targetType: 'fiscal_period',
                targetId: (string) $period->id,
                targetReference: (string) $period->period_name,
                metadata: ['reason' => $reason],
                auditModel: $period,
            );

            return $period->refresh();
        });
    }

    public function assertPeriodMutable(string $tenantId, ?string $fiscalPeriodId, ?string $postingDate, string $operation, ?User $actor = null): void
    {
        $period = null;

        if ($fiscalPeriodId !== null && $fiscalPeriodId !== '') {
            $period = FiscalPeriod::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($fiscalPeriodId);
        } elseif ($postingDate !== null && $postingDate !== '') {
            $day = CarbonImmutable::parse($postingDate)->toDateString();
            $period = FiscalPeriod::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereDate('start_date', '<=', $day)
                ->whereDate('end_date', '>=', $day)
                ->first();
        }

        if ($period !== null && $period->isFiduciaryLocked()) {
            $this->audit->record(
                tenantId: $tenantId,
                action: 'locked_period_mutation_blocked',
                summary: "Blocked [{$operation}] in fiduciary-locked period [{$period->period_name}].",
                actor: $actor,
                targetType: 'fiscal_period',
                targetId: (string) $period->id,
                targetReference: (string) $period->period_name,
                metadata: ['operation' => $operation, 'posting_date' => $postingDate],
            );

            throw FiscalPeriodException::lockedByFiduciary((string) $period->period_name, $postingDate);
        }
    }
}
