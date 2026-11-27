<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use Illuminate\Support\Str;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\LedgerAccountResolver;

/**
 * Posts the journal entries of a chain that no invoice posts by itself (deposit receipts and their resolution, the reclassification
 * of an imputed deposit) and builds the account lines of the previews.
 */
final class ChainJournal
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $posting,
        private readonly FiscalPeriodResolver $periods,
        public readonly LedgerAccountResolver $accounts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function post(DownPaymentChain $chain, string $sourceType, string $sourceId, string $documentNumber, string $date, string $description, array $lines, ?string $userId): JournalEntry
    {
        $tenantId = (string) $chain->tenant_id;

        return $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $this->periods->forDate($tenantId, $date)->id,
            'entry_number' => 'DPC-'.strtoupper(Str::random(8)),
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'document_number' => $documentNumber,
            'posting_date' => $date,
            'document_date' => $date,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => $lines,
        ], $userId);
    }

    /**
     * A journal line for the posting service.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function line(string $accountId, string $debit, string $credit, string $currency, string $description, array $extra = []): array
    {
        return [
            'account_id' => $accountId,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'currency' => $currency,
            'description' => $description,
            ...$extra,
        ];
    }

    /**
     * The line of the account where the money of a deposit moves: the cash, or the bank of the currency.
     */
    public function cashAccountId(string $tenantId, string $currency, string $paymentAccount): string
    {
        return (string) ($paymentAccount === 'cash' ? $this->accounts->cash($tenantId) : $this->accounts->bank($tenantId, $currency))->id;
    }
}
