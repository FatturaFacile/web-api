<?php

declare(strict_types=1);

namespace App\Actions\Banking;

use App\DTOs\Banking\ParsedStatement;
use App\DTOs\Banking\ParsedStatementLine;
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
 */
class ImportBankStatement
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly BankStatementParserManager $parser,
        private readonly BankReconciliationService  $reconciliation,
        private readonly TenantContext              $tenantContext,
    )
    {
    }

    /**
     * @return array{statement: BankStatement, reconciliation: array{reconciled: int, unmatched: int}|null}
     *
     * @throws BankStatementException when the file cannot be parsed
     * @throws ValidationException when the same file has already been imported
     */
    public function execute(string $content, bool $autoReconcile = false): array
    {
        $content = mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        $parsed = $this->parser->parse($content);
        $tenantId = (string)$this->tenantContext->getTenantId();
        $hash = hash('sha256', trim($content));

        if (BankStatement::query()->where('content_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['file' => __('banking.errors.duplicate_statement')]);
        }

        $statement = DB::transaction(function () use ($parsed, $tenantId, $hash): BankStatement {
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

            $this->storeLines($statement, $parsed);

            return $statement;
        });

        $summary = $autoReconcile ? $this->reconciliation->reconcileStatement($statement) : null;

        return ['statement' => $statement->refresh(), 'reconciliation' => $summary];
    }

    private function storeLines(BankStatement $statement, ParsedStatement $parsed): void
    {
        $now = now();

        foreach (array_chunk($parsed->lines, self::INSERT_CHUNK, true) as $chunk) {
            $chunk
                |> array_keys(...)
                |> (fn($x) => array_map(fn(ParsedStatementLine $line, int $index): array => ['id' => (string)Str::uuid(), 'tenant_id' => $statement->tenant_id, 'bank_statement_id' => $statement->id, 'line_number' => $index + 1, 'booking_date' => $line->bookingDate->toDateString(), 'value_date' => $line->valueDate->toDateString(), 'amount' => $line->amount, 'fee_amount' => $line->feeAmount, 'direction' => $line->direction, 'abi_code' => $line->abiCode, 'reference' => $line->reference, 'qr_reference' => $line->qrReference, 'description' => $line->description, 'counterpart_name' => $line->counterpartName, 'counterpart_iban' => $line->counterpartIban, 'reconciled' => false, 'created_at' => $now, 'updated_at' => $now,], $chunk, $x))
                |> array_values(...)
                |> BankStatementLine::query()(...);
        }
    }
}
