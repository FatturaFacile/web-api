<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Keeps the legacy `/api/v1/accounting/ledger-entries` contract alive on top of the one general ledger of the
 * application: the journal entries of the General Ledger module. The legacy tables `general_ledger_entries` /
 * `general_ledger_lines` are no longer written, so an entry posted here gets the period lock, the exact balance check,
 * the immutability and the libro giornale number of every other posting, and shows in trial balance and closings.
 */
final readonly class LegacyLedgerBridge
{
    /**
     * Marks the journal entries posted through the legacy endpoint, so they can be told apart from the module's own
     * manual entries and from the migrated legacy rows (`legacy_gl`).
     */
    public const string SOURCE_LEGACY_API = 'legacy_accounting_api';

    /**
     * Statuses that are part of the ledger: a draft is not booked yet, and the trial balance ignores it too.
     *
     * @var list<string>
     */
    public const array LEDGER_STATUSES = ['posted', 'reversed'];

    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periodResolver,
    ) {}

    /**
     * Translates the legacy lines (`debit`, `credit`) to the lines of the posting service (`debit_amount`,
     * `credit_amount`), so the legacy payload can be checked and posted by the module unchanged.
     *
     * @param  array<int, array{account_id: string, debit: mixed, credit: mixed, description?: string|null}>  $lines
     * @return list<array{account_id: string, debit_amount: string, credit_amount: string, description: string|null}>
     */
    public function journalLines(array $lines): array
    {
        return array_values(array_map(static fn (array $line): array => [
            'account_id' => (string) $line['account_id'],
            'debit_amount' => self::decimal($line['debit']),
            'credit_amount' => self::decimal($line['credit']),
            'description' => isset($line['description']) && $line['description'] !== '' ? mb_substr((string) $line['description'], 0, 255) : null,
        ], $lines));
    }

    /**
     * A legacy amount as a decimal string. JSON numbers arrive as floats: they are read at the 4-decimal scale of the
     * ledger columns, so neither binary noise nor an exponent notation reaches the exact arithmetic.
     */
    private static function decimal(mixed $value): string
    {
        return Decimal::normalize(is_float($value) ? number_format($value, 4, '.', '') : (string) $value);
    }

    /**
     * Totals of the legacy lines in exact decimal arithmetic, for the "unbalanced" answer the legacy endpoint gives.
     *
     * @param  list<array{debit_amount: string, credit_amount: string}>  $journalLines
     * @return array{debit: string, credit: string}
     */
    public function totals(array $journalLines): array
    {
        $debit = '0';
        $credit = '0';

        foreach ($journalLines as $line) {
            $debit = Decimal::add($debit, $line['debit_amount']);
            $credit = Decimal::add($credit, $line['credit_amount']);
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    /**
     * Whether the lines balance exactly, by the rule of the module (no tolerance, positive total).
     *
     * @param  list<array{debit_amount: string, credit_amount: string}>  $journalLines
     */
    public function isBalanced(array $journalLines): bool
    {
        return $this->postingService->validateBalance($journalLines);
    }

    /**
     * Posts a legacy entry as a journal entry of the module, in the fiscal period that contains its date. A missing,
     * closed or GL-locked period is refused by the module (422), never moved to another period.
     *
     * @param  array{entry_number: string, date: string, description: string, lines: array<int, array{account_id: string, debit: mixed, credit: mixed, description?: string|null}>}  $legacyEntry
     * @param  array<string, mixed>  $extra  additional posting data (source reference, bank_balance_managed, ...)
     */
    public function post(string $tenantId, array $legacyEntry, ?string $actorUserId = null, array $extra = []): JournalEntry
    {
        $date = CarbonImmutable::parse($legacyEntry['date'])->toDateString();
        $period = $this->periodResolver->forDate($tenantId, $date);

        return $this->postingService->createAndPost(array_merge([
            'source_document_type' => self::SOURCE_LEGACY_API,
            'source_document_id' => null,
        ], $extra, [
            'tenant_id' => $tenantId,
            'fiscal_period_id' => (string) $period->id,
            'entry_number' => mb_substr((string) $legacyEntry['entry_number'], 0, 50),
            'posting_date' => $date,
            'document_date' => $date,
            'journal_type' => 'standard',
            'description' => (string) $legacyEntry['description'],
            'status' => 'posted',
            'lines' => $this->journalLines($legacyEntry['lines']),
        ]), $actorUserId);
    }

    /**
     * The ledger of the tenant in the legacy listing shape, read from the journal entries so that it shows the same
     * entries as `/api/v1/general-ledger/journals` (drafts excluded, as in the trial balance).
     */
    public function paginate(string $tenantId, int $perPage = 25): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::LEDGER_STATUSES)
            ->orderByDesc('posting_date')
            ->orderByDesc('journal_number')
            ->gridPaginate($perPage)
            ->through(fn (JournalEntry $entry): array => $this->present($entry));
    }

    /**
     * A journal entry in the shape of a legacy `general_ledger_entries` row (same keys and date format), plus the
     * journal fields a client needs to move to the module endpoints.
     *
     * @return array<string, mixed>
     */
    public function present(JournalEntry $entry): array
    {
        return [
            'id' => (string) $entry->id,
            'tenant_id' => (string) $entry->tenant_id,
            'entry_number' => $entry->entry_number,
            'date' => self::serializedDate($entry->getAttribute('posting_date')),
            'description' => $entry->description,
            'reference_type' => $entry->source_document_type,
            'reference_id' => $entry->source_document_id !== null ? (string) $entry->source_document_id : null,
            'is_locked' => in_array($entry->status, self::LEDGER_STATUSES, true),
            'created_at' => self::serializedDate($entry->getAttribute('created_at')),
            'updated_at' => self::serializedDate($entry->getAttribute('updated_at')),
            'deleted_at' => null,
            'journal_entry_id' => (string) $entry->id,
            'journal_number' => $entry->journal_number,
            'fiscal_period_id' => (string) $entry->fiscal_period_id,
            'status' => $entry->status,
            'total_amount' => (float) $entry->total_amount,
        ];
    }

    /**
     * A date in the format Eloquent used to serialise the legacy rows (ISO 8601 with microseconds, UTC), so the legacy
     * clients keep parsing the listing as before.
     */
    private static function serializedDate(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? Carbon::instance($date)->toJSON() : null;
    }
}
