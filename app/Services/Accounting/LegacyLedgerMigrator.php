<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Throwable;

/**
 * Copies the rows of the legacy ledger (`general_ledger_entries` / `general_ledger_lines`) into the journal of the
 * General Ledger module, so the history posted through the legacy endpoint shows in trial balance, closings and the
 * libro giornale.
 *
 * - Idempotent: a copied entry carries `source_document_type = legacy_gl` and `source_document_id = <legacy id>`, and
 *   a legacy row that already has its journal entry is never posted again.
 * - Faithful: each entry keeps its date, number and description, and is booked in the fiscal period of its date.
 * - Conservative: a closed or locked period is never reopened, and a row the module cannot accept (no period, not
 *   balanced, unknown account, ...) is skipped. Every outcome is written to `legacy_ledger_migration_log`.
 * - Non-destructive: the legacy rows are only read, never changed or deleted.
 *
 * The copies do not move bank balances (`bank_balance_managed`): the legacy entries never did.
 */
final readonly class LegacyLedgerMigrator
{
    public const string SOURCE = 'legacy_gl';

    public const string LOG_TABLE = 'legacy_ledger_migration_log';

    public const string STATUS_MIGRATED = 'migrated';

    public const string STATUS_SKIPPED = 'skipped';

    public const string REASON_CLOSED_PERIOD = 'closed_period';

    public const string REASON_NO_FISCAL_PERIOD = 'no_fiscal_period';

    public const string REASON_NO_TENANT = 'no_tenant';

    public const string REASON_SOFT_DELETED = 'soft_deleted';

    public const string REASON_UNBALANCED = 'unbalanced';

    public const string REASON_REJECTED = 'rejected';

    public function __construct(
        private LegacyLedgerBridge $bridge,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Migrates every legacy entry not yet in the journal and returns the counts of the run.
     *
     * @return array{migrated: int, already_migrated: int, skipped: array<string, int>}
     */
    public function migrate(): array
    {
        $result = ['migrated' => 0, 'already_migrated' => 0, 'skipped' => []];

        if (! Schema::hasTable('general_ledger_entries') || ! Schema::hasTable(self::LOG_TABLE)) {
            return $result;
        }

        $this->syncLegacyAccountColumns();

        $rows = DB::table('general_ledger_entries')->orderBy('date')->orderBy('entry_number')->orderBy('id')->lazy(200);

        foreach ($rows as $row) {
            [$status, $reason] = $this->migrateRow($row);

            if ($status === self::STATUS_MIGRATED) {
                $result[$reason === 'already' ? 'already_migrated' : 'migrated']++;

                continue;
            }

            $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
        }

        return $result;
    }

    /**
     * Fills the General Ledger columns of the accounts written by the legacy model (`code` / `type` only): a missing
     * `account_code` takes `code`, and an account still carrying every module default (asset, standard, debit) while
     * its legacy type says otherwise takes the type and its normal balance. Accounts the module wrote are untouched.
     */
    public function syncLegacyAccountColumns(): void
    {
        if (! Schema::hasColumn('chart_of_accounts', 'code') || ! Schema::hasColumn('chart_of_accounts', 'account_code')) {
            return;
        }

        DB::table('chart_of_accounts')->whereNull('account_code')->whereNotNull('code')->update(['account_code' => DB::raw('code')]);

        foreach (ChartOfAccount::NORMAL_BALANCE as $type => $normalBalance) {
            if ($type === 'asset') {
                continue;
            }

            DB::table('chart_of_accounts')
                ->where('type', $type)
                ->where('account_type', 'asset')
                ->where('account_class', 'standard')
                ->where('normal_balance', 'debit')
                ->update(['account_type' => $type, 'account_class' => $type, 'normal_balance' => $normalBalance]);
        }
    }

    /**
     * Migrates one legacy entry.
     *
     * @return array{0: string, 1: string|null} status and reason ("already" for an entry copied by an earlier run)
     */
    private function migrateRow(object $row): array
    {
        $entryId = (string) $row->id;

        if ($row->deleted_at !== null) {
            return $this->skip($row, self::REASON_SOFT_DELETED, 'The legacy entry is soft deleted.');
        }

        if ($row->tenant_id === null || ($tenant = Tenant::find($row->tenant_id)) === null) {
            return $this->skip($row, self::REASON_NO_TENANT, 'The legacy entry belongs to no tenant.');
        }

        $existing = DB::table('journal_entries')->where('source_document_type', self::SOURCE)->where('source_document_id', $entryId)->value('id');

        if ($existing !== null) {
            $this->log($row, self::STATUS_MIGRATED, null, null, (string) $existing);

            return [self::STATUS_MIGRATED, 'already'];
        }

        $date = CarbonImmutable::parse($row->date)->toDateString();
        $periods = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->whereNull('deleted_at')
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->get();

        if ($periods->isEmpty()) {
            return $this->skip($row, self::REASON_NO_FISCAL_PERIOD, "No fiscal period contains {$date}.");
        }

        if (! $periods->contains(static fn (FiscalPeriod $period): bool => $period->status === 'open' && ! $period->isLockedFor('GL'))) {
            return $this->skip($row, self::REASON_CLOSED_PERIOD, "The fiscal period containing {$date} is closed or locked: it is not reopened.");
        }

        $lines = DB::table('general_ledger_lines')->where('general_ledger_entry_id', $entryId)->orderBy('line_number')->orderBy('id')
            ->get(['account_id', 'debit', 'credit', 'description'])
            ->map(static fn (object $line): array => ['account_id' => (string) $line->account_id, 'debit' => (string) $line->debit, 'credit' => (string) $line->credit, 'description' => $line->description])
            ->all();

        if (! $this->bridge->isBalanced($this->bridge->journalLines($lines))) {
            return $this->skip($row, self::REASON_UNBALANCED, 'Debit and credit do not balance exactly.');
        }

        try {
            $journal = $this->tenantContext->runFor($tenant, fn () => $this->bridge->post((string) $row->tenant_id, [
                'entry_number' => (string) $row->entry_number,
                'date' => $date,
                'description' => (string) $row->description,
                'lines' => $lines,
            ], null, [
                'source_document_type' => self::SOURCE,
                'source_document_id' => $entryId,
                'document_number' => mb_substr((string) $row->entry_number, 0, 50),
                'bank_balance_managed' => true,
            ]));
        } catch (Throwable $exception) {
            return $this->skip($row, self::REASON_REJECTED, $exception->getMessage());
        }

        $this->log($row, self::STATUS_MIGRATED, null, null, (string) $journal->id);

        return [self::STATUS_MIGRATED, null];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function skip(object $row, string $reason, string $message): array
    {
        $this->log($row, self::STATUS_SKIPPED, $reason, $message, null);

        return [self::STATUS_SKIPPED, $reason];
    }

    /**
     * One log row per legacy entry, updated by every run, so the log always tells the latest outcome.
     */
    private function log(object $row, string $status, ?string $reason, ?string $message, ?string $journalEntryId): void
    {
        $now = now();
        $existing = DB::table(self::LOG_TABLE)->where('general_ledger_entry_id', $row->id)->first(['id', 'attempts']);

        $values = [
            'tenant_id' => $row->tenant_id,
            'entry_number' => mb_substr((string) $row->entry_number, 0, 50),
            'entry_date' => CarbonImmutable::parse($row->date)->toDateString(),
            'status' => $status,
            'reason' => $reason,
            'message' => $message,
            'journal_entry_id' => $journalEntryId,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            DB::table(self::LOG_TABLE)->insert($values + ['id' => (string) Str::uuid7(), 'general_ledger_entry_id' => $row->id, 'attempts' => 1, 'created_at' => $now]);

            return;
        }

        DB::table(self::LOG_TABLE)->where('id', $existing->id)->update($values + ['attempts' => (int) $existing->attempts + 1]);
    }
}
