<?php

declare(strict_types=1);

namespace App\Actions\Banking;

use App\DTOs\Banking\ParsedStatement;
use App\Exceptions\BankStatementException;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Services\Banking\BankReconciliationService;
use App\Services\Banking\BankStatementParserManager;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads a bank statement file (CBI, CAMT.053 or CAMT.054, detected automatically), stores it with its lines and, on
 * request, reconciles the incoming lines with the open invoices of the tenant.
 *
 * Importing is idempotent: the same file is refused, and a movement that an earlier statement already holds (an
 * overlapping camt.053, or the camt.054 notification of a booking that the day statement repeats) is recognised by its
 * bank reference (AcctSvcrRef / NtryRef) and stored only once.
 */
class ImportBankStatement
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly BankStatementParserManager $parser,
        private readonly BankReconciliationService $reconciliation,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{statement: BankStatement, reconciliation: array{reconciled: int, unmatched: int}|null, duplicates: int}
     *
     * @throws BankStatementException when the file cannot be parsed
     * @throws ValidationException when the same file has already been imported
     */
    public function execute(string $content, bool $autoReconcile = false): array
    {
        $content = mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

        return $this->executeParsed($this->parser->parse($content), hash('sha256', trim($content)), $autoReconcile);
    }

    /**
     * Stores a statement that was already read (CSV with a mapping, an open banking pull). `$hash` identifies the
     * payload: the same payload is refused, and single movements are de-duplicated across statements as for files.
     *
     * @return array{statement: BankStatement, reconciliation: array{reconciled: int, unmatched: int}|null, duplicates: int}
     *
     * @throws ValidationException when the same payload has already been imported
     */
    public function executeParsed(ParsedStatement $parsed, string $hash, bool $autoReconcile = false): array
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        if (BankStatement::query()->where('content_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['file' => __('banking.errors.duplicate_statement')]);
        }

        $duplicates = 0;

        $statement = DB::transaction(function () use ($parsed, $tenantId, $hash, &$duplicates): BankStatement {
            $statement = BankStatement::create([
                'tenant_id' => $tenantId,
                'source_format' => $parsed->sourceFormat,
                'account_iban' => $parsed->accountIban,
                'statement_date' => $parsed->statementDate->toDateString(),
                'opening_balance' => $parsed->openingBalance,
                'closing_balance' => $parsed->closingBalance,
                'currency' => $parsed->currency,
                'total_entries' => $parsed->totalEntries(),
                'status' => BankStatement::DRAFT,
                'content_hash' => $hash,
            ]);

            $duplicates = $this->storeLines($statement, $parsed);

            if ($duplicates > 0) {
                $statement->update(['duplicate_lines' => $duplicates]);
            }

            return $statement;
        });

        $summary = $autoReconcile ? $this->reconciliation->reconcileStatement($statement) : null;

        return ['statement' => $statement->refresh(), 'reconciliation' => $summary, 'duplicates' => $duplicates];
    }

    /**
     * @return int the number of lines skipped because an earlier statement already holds them
     */
    private function storeLines(BankStatement $statement, ParsedStatement $parsed): int
    {
        $now = now();
        $keys = $this->dedupeKeys($statement, $parsed);
        $known = $keys === [] ? [] : array_flip(
            BankStatementLine::query()->whereIn('dedupe_key', array_unique($keys))->pluck('dedupe_key')->all()
        );
        $rows = [];
        $duplicates = 0;

        foreach ($parsed->lines as $index => $line) {
            if (isset($known[$keys[$index]])) {
                $duplicates++;

                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $statement->tenant_id,
                'bank_statement_id' => $statement->id,
                'line_number' => $index + 1,
                'booking_date' => $line->bookingDate->toDateString(),
                'value_date' => $line->valueDate->toDateString(),
                'amount' => $line->amount,
                'fee_amount' => $line->feeAmount,
                'currency' => $statement->currency,
                'direction' => $line->direction,
                'abi_code' => $line->abiCode,
                'reference' => $line->reference,
                'qr_reference' => $line->qrReference,
                'entry_reference' => $line->entryReference,
                'dedupe_key' => $keys[$index],
                'description' => $line->description,
                'counterpart_name' => $line->counterpartName,
                'counterpart_iban' => $line->counterpartIban,
                'reconciled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            BankStatementLine::insert($chunk);
        }

        return $duplicates;
    }

    /**
     * One key per line: the bank reference when there is one, otherwise the content of the movement. Identical
     * movements inside one file get a running number, so only a repetition across files counts as a duplicate.
     *
     * @return array<int, string>
     */
    private function dedupeKeys(BankStatement $statement, ParsedStatement $parsed): array
    {
        $seen = [];
        $keys = [];

        foreach ($parsed->lines as $index => $line) {
            $amount = number_format($line->amount, 2, '.', '');
            $base = implode('|', [
                $statement->account_iban,
                $line->direction,
                $line->entryReference !== null
                    ? $line->entryReference.'|'.$amount
                    : implode('|', [$line->bookingDate->toDateString(), $amount, $line->reference, $line->qrReference, $line->counterpartIban, $line->description]),
            ]);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $keys[$index] = hash('sha256', $base.'#'.$seen[$base]);
        }

        return $keys;
    }
}
