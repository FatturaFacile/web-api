<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Runs, locks and deletes consolidations. A run is a stored snapshot of ConsolidationCalculator for one fiscal period
 * of the parent entity: it never writes to the ledger of any entity, only to the `gl_consolidation_*` tables of the
 * group owner, and it is never edited afterwards. Asking again for the same period makes version n + 1.
 *
 * Reproducible: the run stores the SHA-256 of its inputs (members, shares, rates, mappings, entity balances);
 * `verify()` recomputes it from the live ledgers, so a changed ledger or rate is detected. Lockable: a locked run is the
 * official consolidation of its period (one per group and period); it can be locked only while its inputs are
 * unchanged, and a locked run cannot be deleted. Audit-logged: creation, lock and deletion are recorded in the audit
 * ledger with the fingerprint and the key figures, on top of the model events of the audit trail.
 *
 * Every entry point first checks that the user can read the ledger of every member entity (ConsolidationAccessGuard).
 */
final readonly class ConsolidationService
{
    private const string AuditModule = 'MODULE_22_GENERAL_LEDGER';

    public function __construct(
        private ConsolidationCalculator $calculator,
        private ConsolidationAccessGuard $guard,
        private ConsolidatedStatementService $statements,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * @throws ConsolidationException when the figures cannot be built (no rate, a method not implemented)
     */
    public function run(User $user, ConsolidationGroup $group, FiscalPeriod $period): ConsolidationRun
    {
        $this->guard->assertCanReadGroup($user, $group);

        if ((string) $period->tenant_id !== (string) $group->tenant_id) {
            throw new ConsolidationException('The fiscal period must belong to the parent entity of the group.');
        }

        $yearStart = $this->yearStart($period);
        $periodStart = CarbonImmutable::parse($period->start_date);
        $periodEnd = CarbonImmutable::parse($period->end_date);

        $run = DB::transaction(function () use ($user, $group, $period, $yearStart, $periodStart, $periodEnd): ConsolidationRun {
            ConsolidationGroup::withoutGlobalScopes()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            $draft = $this->calculator->compute($group, $yearStart, $periodEnd);
            $accounts = $this->statements->consolidatedAccounts(
                $this->fullBalances($draft),
                $this->adjustmentLines($draft),
                array_keys($draft->members),
            );
            $trial = $this->statements->trialBalanceOf($accounts);
            $sheet = $this->statements->balanceSheetOf($accounts, $draft->nonControllingInterests, $this->statements->codesOf($group));
            $income = $this->statements->incomeStatementOf($accounts, $draft->nonControllingInterests, $this->statements->codesOf($group));

            $version = 1 + (int) ConsolidationRun::withoutGlobalScopes()->withTrashed()
                ->where('group_id', $group->id)
                ->where('fiscal_period_id', $period->id)
                ->max('version');

            $run = ConsolidationRun::create([
                'tenant_id' => $group->tenant_id,
                'group_id' => $group->id,
                'fiscal_period_id' => $period->id,
                'year_start' => $yearStart->toDateString(),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'version' => $version,
                'status' => ConsolidationRun::StatusCompleted,
                'group_currency' => strtoupper($group->group_currency),
                'input_fingerprint' => $this->calculator->fingerprint($group, $draft, $yearStart, $periodEnd),
                'members_snapshot' => array_values($draft->members),
                'mappings_snapshot' => $draft->mappings,
                'rates' => $draft->rates,
                'warnings' => $draft->warnings,
                'inputs_snapshot' => $draft->extensions,
                'totals' => [
                    'adjustment_count' => count($draft->adjustments),
                    'warning_count' => count($draft->warnings),
                    'trial_balance_is_balanced' => $trial['is_balanced'],
                    'total_assets' => $sheet['total_assets'],
                    'total_liabilities' => $sheet['total_liabilities'],
                    'total_equity' => $sheet['total_equity'],
                    'balance_sheet_is_balanced' => $sheet['is_balanced'],
                    'total_revenue' => $income['total_revenue'],
                    'net_income' => $income['net_income'],
                    'non_controlling_interests' => $draft->nonControllingInterests,
                ],
                'created_by' => $user->id,
            ]);

            $this->store($run, $draft);

            return $run;
        });

        $this->audit($run, 'consolidation_run_created', null, [
            'group_id' => $run->group_id,
            'fiscal_period_id' => $run->fiscal_period_id,
            'version' => $run->version,
            'input_fingerprint' => $run->input_fingerprint,
            'totals' => $run->totals,
            'warnings' => $run->warnings,
        ]);

        return $run;
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function assertCanRead(User $user, ConsolidationGroup $group): void
    {
        $this->guard->assertCanReadGroup($user, $group);
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function assertCanReadRun(User $user, ConsolidationRun $run): void
    {
        $this->guard->assertCanReadGroup($user, $this->groupOf($run));
    }

    /**
     * Recomputes the inputs of a run from the live ledgers and compares them with the stored fingerprint.
     *
     * @return array{matches: bool, stored_fingerprint: string, current_fingerprint: string}
     */
    public function verify(User $user, ConsolidationRun $run): array
    {
        $group = $this->groupOf($run);
        $this->guard->assertCanReadGroup($user, $group);

        $current = $this->currentFingerprint($group, $run);

        return [
            'matches' => hash_equals($run->input_fingerprint, $current),
            'stored_fingerprint' => $run->input_fingerprint,
            'current_fingerprint' => $current,
        ];
    }

    /**
     * @throws ConflictHttpException when the run is already locked, another run of the period is locked, or the inputs changed since
     */
    public function lock(User $user, ConsolidationRun $run): ConsolidationRun
    {
        $group = $this->groupOf($run);
        $this->guard->assertCanReadGroup($user, $group);

        $locked = DB::transaction(function () use ($user, $group, $run): ConsolidationRun {
            $fresh = ConsolidationRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($fresh->isLocked()) {
                throw new ConflictHttpException('The run is already locked.');
            }

            $other = ConsolidationRun::query()
                ->where('group_id', $fresh->group_id)
                ->where('fiscal_period_id', $fresh->fiscal_period_id)
                ->where('status', ConsolidationRun::StatusLocked)
                ->exists();

            if ($other) {
                throw new ConflictHttpException('Another run of this period is already locked.');
            }

            if (! hash_equals($fresh->input_fingerprint, $this->currentFingerprint($group, $fresh))) {
                throw new ConflictHttpException('The ledgers, the exchange rates or the group configuration changed since this run: create a new run and lock that one.');
            }

            $fresh->update(['status' => ConsolidationRun::StatusLocked, 'locked_at' => now(), 'locked_by' => $user->id]);

            return $fresh;
        });

        $this->audit($locked, 'consolidation_run_locked', ['status' => ConsolidationRun::StatusCompleted], ['status' => ConsolidationRun::StatusLocked, 'locked_by' => $user->id, 'input_fingerprint' => $locked->input_fingerprint]);

        return $locked;
    }

    /**
     * @throws ConflictHttpException when the run is locked
     */
    public function delete(User $user, ConsolidationRun $run): void
    {
        $this->guard->assertCanReadGroup($user, $this->groupOf($run));

        DB::transaction(function () use ($run): void {
            $fresh = ConsolidationRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($fresh->isLocked()) {
                throw new ConflictHttpException('A locked run cannot be deleted.');
            }

            foreach (['gl_consolidation_adjustment_lines', 'gl_consolidation_adjustments', 'gl_consolidation_run_balances'] as $table) {
                DB::table($table)->where('run_id', $fresh->id)->update(['deleted_at' => now()]);
            }

            $fresh->delete();
        });

        $this->audit($run, 'consolidation_run_deleted', ['version' => $run->version, 'input_fingerprint' => $run->input_fingerprint], null);
    }

    private function currentFingerprint(ConsolidationGroup $group, ConsolidationRun $run): string
    {
        $yearStart = CarbonImmutable::parse($run->year_start);
        $periodEnd = CarbonImmutable::parse($run->period_end);

        return $this->calculator->fingerprint($group, $this->calculator->compute($group, $yearStart, $periodEnd), $yearStart, $periodEnd);
    }

    private function groupOf(ConsolidationRun $run): ConsolidationGroup
    {
        return ConsolidationGroup::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->findOrFail($run->group_id);
    }

    private function yearStart(FiscalPeriod $period): CarbonImmutable
    {
        $year = FiscalYear::withoutGlobalScopes()->where('tenant_id', $period->tenant_id)->find($period->fiscal_year_id);

        if ($year === null) {
            throw new ConsolidationException('The fiscal period has no fiscal year.');
        }

        return CarbonImmutable::parse($year->start_date);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fullBalances(ConsolidationDraft $draft): array
    {
        return array_values(array_filter($draft->balances, static fn (array $row): bool => in_array($row['consolidation_method'], ['full', 'proportional'], true)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function adjustmentLines(ConsolidationDraft $draft): array
    {
        $lines = [];

        foreach ($draft->adjustments as $adjustment) {
            array_push($lines, ...$adjustment['lines']);
        }

        return $lines;
    }

    private function store(ConsolidationRun $run, ConsolidationDraft $draft): void
    {
        $now = now();
        $tenantId = (string) $run->tenant_id;

        foreach (array_chunk($draft->balances, 500) as $chunk) {
            DB::table('gl_consolidation_run_balances')->insert(array_map(static fn (array $row): array => [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'run_id' => $run->id,
                'member_tenant_id' => $row['member_tenant_id'],
                'account_id' => $row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'account_type' => $row['account_type'],
                'normal_balance' => $row['normal_balance'],
                'consolidation_method' => $row['consolidation_method'],
                'ownership_percentage' => $row['ownership_percentage'],
                'consolidated_share' => $row['consolidated_share'],
                'local_currency' => $row['local_currency'],
                'local_net' => $row['local_net'],
                'rate_basis' => $row['rate_basis'],
                'rate' => $row['rate'],
                'group_net' => $row['group_net'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        foreach ($draft->adjustments as $adjustment) {
            $adjustmentId = (string) Str::uuid7();

            DB::table('gl_consolidation_adjustments')->insert([
                'id' => $adjustmentId,
                'tenant_id' => $tenantId,
                'run_id' => $run->id,
                'adjustment_type' => $adjustment['type'],
                'relation_id' => $adjustment['relation_id'],
                'member_tenant_id' => $adjustment['member_tenant_id'],
                'description' => $adjustment['description'],
                'amount' => $adjustment['amount'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('gl_consolidation_adjustment_lines')->insert(array_map(static fn (array $line, int $index): array => [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'run_id' => $run->id,
                'adjustment_id' => $adjustmentId,
                'line_number' => $index + 1,
                'entity_tenant_id' => $line['entity_tenant_id'],
                'account_code' => $line['account_code'],
                'account_name' => $line['account_name'],
                'account_type' => $line['account_type'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $adjustment['lines'], array_keys($adjustment['lines'])));
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(ConsolidationRun $run, string $action, ?array $before, ?array $after): void
    {
        $this->auditLedger->record(
            action: $action,
            model: $run,
            payloadBefore: $before,
            payloadAfter: $after,
            moduleCode: self::AuditModule,
        );
    }
}
