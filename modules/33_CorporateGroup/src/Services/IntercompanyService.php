<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Services;

use Carbon\Carbon;
use Enterprise\MultiTenant\Services\CompanyContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\CorporateGroup\Models\Company;
use Modules\CorporateGroup\Models\CompanyAccountingSetting;
use Modules\CorporateGroup\Models\IntercompanyDocument;
use Modules\CorporateGroup\Models\IntercompanyTransaction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * Books an operation between two companies of the group on both sides at once:
 * seller → sales order + sales invoice + journal entry; buyer → purchase order + purchase invoice + mirror journal entry.
 */
final class IntercompanyService
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $posting,
        private readonly CompanySequenceService $sequences,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data  seller_company_id, buyer_company_id, transaction_date, net_amount, tax_amount?,
     *                                      transaction_type?, currency?, description?, reference?, lines?
     */
    public function record(string $tenantId, array $data, ?string $userId = null): IntercompanyTransaction
    {
        $seller = Company::findOrFail($data['seller_company_id']);
        $buyer = Company::findOrFail($data['buyer_company_id']);

        if ($seller->id === $buyer->id) {
            throw new InvalidArgumentException('An intercompany transaction needs two different companies.');
        }
        if (! $seller->is_active || ! $buyer->is_active) {
            throw new InvalidArgumentException('Both companies must be active.');
        }

        $net = round((float) $data['net_amount'], 4);
        $tax = round((float) ($data['tax_amount'] ?? 0), 4);
        if ($net <= 0 || $tax < 0) {
            throw new InvalidArgumentException('The net amount must be positive and the tax amount cannot be negative.');
        }
        $gross = round($net + $tax, 4);

        $currency = strtoupper((string) ($data['currency'] ?? $seller->currency));
        if ($seller->currency !== $currency || $buyer->currency !== $currency) {
            throw new InvalidArgumentException('Cross-currency intercompany transactions are not supported: both companies must book in the transaction currency.');
        }

        $sellerSettings = $this->settingsFor($seller, ['ic_receivable_account_id', 'ic_revenue_account_id'], $tax > 0 ? ['vat_payable_account_id'] : []);
        $buyerSettings = $this->settingsFor($buyer, ['ic_payable_account_id', 'ic_expense_account_id'], $tax > 0 ? ['vat_receivable_account_id'] : []);

        $date = Carbon::parse((string) $data['transaction_date']);
        $description = (string) ($data['description'] ?? 'Intercompany '.($data['transaction_type'] ?? 'goods'));
        $lines = $data['lines'] ?? [['description' => $description, 'quantity' => 1, 'unit_price' => $net]];

        return DB::transaction(function () use (
            $tenantId, $data, $seller, $buyer, $sellerSettings, $buyerSettings, $net, $tax, $gross, $currency, $date, $description, $lines, $userId
        ): IntercompanyTransaction {
            $docs = [];
            $buildDocument = function (IntercompanyTransaction $transaction, Company $company, Company $counterparty, string $side, string $type, string $series) use (
                $tenantId, $net, $tax, $gross, $currency, $date, $lines, &$docs
            ): IntercompanyDocument {
                $doc = IntercompanyDocument::create([
                    'tenant_id' => $tenantId,
                    'transaction_id' => $transaction->id,
                    'company_id' => $company->id,
                    'counterparty_company_id' => $counterparty->id,
                    'side' => $side,
                    'document_type' => $type,
                    'series_code' => $series,
                    'document_number' => $this->sequences->next($tenantId, (string) $company->id, $series, (int) $date->format('Y')),
                    'document_date' => $date->toDateString(),
                    'currency' => $currency,
                    'net_amount' => $net,
                    'tax_amount' => $tax,
                    'gross_amount' => $gross,
                    'lines' => $lines,
                    'status' => 'issued',
                ]);
                $docs[$type] = $doc;

                return $doc;
            };

            $transaction = IntercompanyTransaction::create([
                'tenant_id' => $tenantId,
                'seller_company_id' => $seller->id,
                'buyer_company_id' => $buyer->id,
                'reference' => (string) ($data['reference'] ?? ''),
                'transaction_type' => $data['transaction_type'] ?? 'goods',
                'transaction_date' => $date->toDateString(),
                'currency' => $currency,
                'net_amount' => $net,
                'tax_amount' => $tax,
                'gross_amount' => $gross,
                'description' => $description,
                'status' => 'posted',
                'created_by' => $userId,
            ]);

            // Seller side: sales order and sales invoice; buyer side: the mirrored purchase documents.
            $buildDocument($transaction, $seller, $buyer, 'seller', 'sales_order', 'ICSO');
            $sellerInvoice = $buildDocument($transaction, $seller, $buyer, 'seller', 'sales_invoice', 'ICSI');
            $buildDocument($transaction, $buyer, $seller, 'buyer', 'purchase_order', 'ICPO');
            $buyerInvoice = $buildDocument($transaction, $buyer, $seller, 'buyer', 'purchase_invoice', 'ICPI');

            if (($data['reference'] ?? '') === '') {
                $transaction->reference = (string) $sellerInvoice->document_number;
                $transaction->save();
            }

            $sellerLines = [
                ['account_id' => $sellerSettings->ic_receivable_account_id, 'debit_amount' => $gross, 'credit_amount' => 0, 'description' => 'Intercompany receivable '.$buyer->code],
                ['account_id' => $sellerSettings->ic_revenue_account_id, 'debit_amount' => 0, 'credit_amount' => $net, 'description' => 'Intercompany revenue '.$buyer->code],
            ];
            if ($tax > 0) {
                $sellerLines[] = ['account_id' => $sellerSettings->vat_payable_account_id, 'debit_amount' => 0, 'credit_amount' => $tax, 'description' => 'Output VAT'];
            }

            $buyerLines = [
                ['account_id' => $buyerSettings->ic_expense_account_id, 'debit_amount' => $net, 'credit_amount' => 0, 'description' => 'Intercompany cost '.$seller->code],
            ];
            if ($tax > 0) {
                $buyerLines[] = ['account_id' => $buyerSettings->vat_receivable_account_id, 'debit_amount' => $tax, 'credit_amount' => 0, 'description' => 'Input VAT'];
            }
            $buyerLines[] = ['account_id' => $buyerSettings->ic_payable_account_id, 'debit_amount' => 0, 'credit_amount' => $gross, 'description' => 'Intercompany payable '.$seller->code];

            $sellerEntry = $this->postFor($seller, $tenantId, $date, $sellerInvoice, $sellerLines, $description, $userId);
            $buyerEntry = $this->postFor($buyer, $tenantId, $date, $buyerInvoice, $buyerLines, $description, $userId);

            $sellerInvoice->journal_entry_id = $sellerEntry->id;
            $sellerInvoice->save();
            $buyerInvoice->journal_entry_id = $buyerEntry->id;
            $buyerInvoice->save();

            $transaction->seller_journal_entry_id = $sellerEntry->id;
            $transaction->buyer_journal_entry_id = $buyerEntry->id;
            $transaction->save();

            return $transaction->load(['documents', 'seller', 'buyer']);
        });
    }

    /**
     * Reverses both journal entries and voids the documents of the transaction.
     */
    public function cancel(IntercompanyTransaction $transaction, ?string $reversalDate = null, ?string $reason = null, ?string $userId = null): IntercompanyTransaction
    {
        if ($transaction->status === 'cancelled') {
            throw new InvalidArgumentException('The intercompany transaction is already cancelled.');
        }

        $reversalDate ??= date('Y-m-d');

        return DB::transaction(function () use ($transaction, $reversalDate, $reason, $userId): IntercompanyTransaction {
            $reason ??= 'Intercompany transaction '.$transaction->reference.' cancelled';

            foreach ([
                [$transaction->seller_company_id, $transaction->seller_journal_entry_id],
                [$transaction->buyer_company_id, $transaction->buyer_journal_entry_id],
            ] as [$companyId, $entryId]) {
                if ($entryId === null) {
                    continue;
                }
                $this->companyContext->runWithCompany((string) $companyId, fn () => $this->posting->reverseJournal((string) $entryId, $reversalDate, $reason, $userId));
            }

            $transaction->documents()->update(['status' => 'cancelled']);
            $transaction->status = 'cancelled';
            $transaction->save();

            return $transaction->load('documents');
        });
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $requiredWhenTaxed
     */
    private function settingsFor(Company $company, array $required, array $requiredWhenTaxed): CompanyAccountingSetting
    {
        $settings = CompanyAccountingSetting::query()->where('company_id', $company->id)->first();

        foreach ([...$required, ...$requiredWhenTaxed] as $field) {
            if ($settings === null || empty($settings->{$field})) {
                throw new InvalidArgumentException("Company {$company->code} has no '{$field}' configured for intercompany postings.");
            }
        }

        return $settings;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function postFor(Company $company, string $tenantId, Carbon $date, IntercompanyDocument $invoice, array $lines, string $description, ?string $userId): JournalEntry
    {
        return $this->companyContext->runWithCompany((string) $company->id, function () use ($company, $tenantId, $date, $invoice, $lines, $description, $userId): JournalEntry {
            $period = $this->periodFor((string) $company->id, $date);

            return $this->posting->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'JE-'.$invoice->document_number,
                'posting_date' => $date->toDateString(),
                'document_date' => $date->toDateString(),
                'description' => $description.' ('.$invoice->document_number.')',
                'source_document_type' => 'intercompany_invoice',
                'source_document_id' => $invoice->id,
                'lines' => $lines,
            ], $userId);
        });
    }

    private function periodFor(string $companyId, Carbon $date): FiscalPeriod
    {
        $day = $date->toDateString();
        $nextDay = $date->copy()->addDay()->toDateString();

        // The company's own period wins over a generic (company-less) one.
        $period = FiscalPeriod::query()
            ->where('start_date', '<', $nextDay)
            ->where('end_date', '>=', $day)
            ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId])
            ->first();

        if ($period === null) {
            throw new InvalidArgumentException("No fiscal period covers {$day} for the company.");
        }

        return $period;
    }
}
