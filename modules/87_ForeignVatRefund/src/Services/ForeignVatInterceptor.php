<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ForeignVatRefund\Domain\Enums\ExpenseCategory;
use Modules\ForeignVatRefund\Domain\Enums\LedgerStatus;
use Modules\ForeignVatRefund\Domain\Enums\SourceType;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Domain\Support\ExpenseCategoryGuesser;
use Modules\ForeignVatRefund\Domain\Support\Jurisdictions;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Throwable;

/**
 * Recognises the foreign VAT of supplier documents and expense reports: when the VAT belongs to a country other than the one the
 * company is established in, the refund matrix of that country decides the recoverable share. The recoverable VAT is parked on the
 * transitory receivable account, the rest is booked as a cost, and a ledger line records both for the refund claim.
 */
final class ForeignVatInterceptor
{
    public function __construct(
        private readonly RefundRuleResolver $rules,
        private readonly ForeignVatCalculator $calculator,
        private readonly ForeignVatPoster $poster,
        private readonly ForeignVatAccounts $accounts,
    ) {}

    /**
     * @return list<ForeignVatLedgerEntry>
     */
    public function fromPassiveInvoice(PassiveInvoice $invoice): array
    {
        $tenantId = (string) $invoice->tenant_id;

        // A supplier credit note would be a negative refund line: it is handled in the ordinary VAT settlement.
        if (! $this->accounts->settings($tenantId)->auto_intercept || $invoice->document_type === 'TD04') {
            return [];
        }

        $supplier = Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($invoice->supplier_id)->first();
        $country = Jurisdictions::countryOfVatNumber($supplier?->vat_number) ?? strtoupper((string) $supplier?->country);

        if ($country === '') {
            return [];
        }

        $name = $supplier === null ? null : (trim((string) $supplier->company_name) !== '' ? (string) $supplier->company_name : trim($supplier->first_name.' '.$supplier->last_name));
        $currency = (string) ($invoice->currency ?? 'EUR');
        $rate = (string) ($invoice->exchange_rate ?? '1');
        $common = [
            'tenant_id' => $tenantId,
            'source_type' => SourceType::PassiveInvoice,
            'document_number' => (string) $invoice->invoice_number,
            'document_date' => $invoice->date->format('Y-m-d'),
            'supplier_name' => $name,
            'supplier_vat_number' => $supplier?->vat_number,
            'supplier_country_code' => $country,
            'currency_code' => $currency,
            'exchange_rate_to_base' => $rate,
            'credit_role' => PostingAccountRole::InputVat,
        ];

        $lines = PassiveInvoiceLine::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('passive_invoice_id', $invoice->id)->orderBy('line_number')->get();
        $recorded = [];

        if ($lines->isEmpty()) {
            $totalNet = $invoice->getAttribute('total_net');
            $net = $totalNet !== null ? (string) $totalNet : Decimal::round2(Decimal::sub((string) $invoice->total_amount, (string) $invoice->total_tax));
            $vat = (string) $invoice->total_tax;
            $percentage = Decimal::isPositive($net) ? Decimal::round2(Decimal::div(Decimal::mul($vat, '100'), $net)) : '0';
            $entry = $this->record($common + ['source_id' => (string) $invoice->id, 'expense_category_code' => ExpenseCategory::Other->value, 'net_amount' => $net, 'foreign_vat_rate' => $percentage, 'foreign_vat_amount' => $vat]);

            return $entry === null ? [] : [$entry];
        }

        foreach ($lines as $line) {
            if (! Decimal::isPositive((string) $line->tax_rate)) {
                continue;
            }

            $net = (string) $line->line_total;
            $entry = $this->record($common + [
                'source_id' => (string) $line->id,
                'expense_category_code' => ExpenseCategoryGuesser::guess($line->description),
                'net_amount' => $net,
                'foreign_vat_rate' => (string) $line->tax_rate,
                'foreign_vat_amount' => $this->calculator->vatOf($net, (string) $line->tax_rate),
            ]);

            if ($entry !== null) {
                $recorded[] = $entry;
            }
        }

        return $recorded;
    }

    /**
     * @param  bool  $post  false when the report is only approved: the lines are recorded and wait for the payout posting
     * @return list<ForeignVatLedgerEntry>
     */
    public function fromExpenseReport(string $reportId, string $tenantId, bool $post): array
    {
        if (! $this->accounts->settings($tenantId)->auto_intercept) {
            return [];
        }

        $report = ExpenseReport::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($reportId)->first();

        if ($report === null) {
            return [];
        }

        $recorded = [];

        foreach (ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('expense_report_id', $reportId)->with('category')->get() as $line) {
            $country = Jurisdictions::countryOfVatNumber($line->merchant_tax_id);
            $vatBase = (string) $line->vat_amount;
            $fx = (string) $line->exchange_rate;

            if ($country === null || ! Decimal::isPositive($vatBase) || ! Decimal::isPositive($fx)) {
                continue;
            }

            $vatDocument = Decimal::round2(Decimal::div($vatBase, $fx));
            $entry = $this->record([
                'tenant_id' => $tenantId,
                'source_type' => SourceType::ExpenseReport,
                'source_id' => (string) $line->id,
                'document_number' => $report->report_number,
                'document_date' => $line->expense_date->format('Y-m-d'),
                'supplier_name' => $line->merchant_name,
                'supplier_vat_number' => $line->merchant_tax_id,
                'supplier_country_code' => $country,
                'expense_category_code' => ExpenseCategoryGuesser::guess(trim($line->description.' '.($line->category->name ?? '').' '.$line->merchant_name)),
                'net_amount' => Decimal::round2(Decimal::sub((string) $line->original_amount, $vatDocument)),
                'foreign_vat_rate' => (string) $line->vat_rate,
                'foreign_vat_amount' => $vatDocument,
                'currency_code' => (string) $line->original_currency,
                'exchange_rate_to_base' => $fx,
                'credit_role' => PostingAccountRole::PurchaseExpense,
            ], $post);

            if ($entry !== null) {
                $recorded[] = $entry;
            }
        }

        return $recorded;
    }

    /**
     * A foreign VAT item entered by hand (a receipt that never went through a supplier invoice).
     *
     * @param  array<string, mixed>  $data
     */
    public function registerManual(string $tenantId, array $data, bool $post = true): ForeignVatLedgerEntry
    {
        $entry = $this->record([
            'tenant_id' => $tenantId,
            'source_type' => SourceType::Manual,
            'source_id' => null,
            'document_number' => (string) $data['document_number'],
            'document_date' => (string) $data['document_date'],
            'supplier_name' => $data['supplier_name'] ?? null,
            'supplier_vat_number' => $data['supplier_vat_number'] ?? null,
            'supplier_country_code' => (string) $data['supplier_country_code'],
            'expense_category_code' => (int) $data['expense_category_code'],
            'net_amount' => (string) $data['net_amount'],
            'foreign_vat_rate' => (string) $data['foreign_vat_rate'],
            'foreign_vat_amount' => (string) $data['foreign_vat_amount'],
            'currency_code' => (string) $data['currency_code'],
            'exchange_rate_to_base' => (string) ($data['exchange_rate_to_base'] ?? '1'),
            'credit_role' => ($data['vat_booked_on'] ?? 'expense') === 'input_vat' ? PostingAccountRole::InputVat : PostingAccountRole::PurchaseExpense,
        ], $post);

        return $entry ?? throw ForeignVatException::because('not_a_foreign_vat_item');
    }

    /**
     * Applies another category (or country) to an item still open and books the difference: the previous entry is reversed and a new one posted.
     */
    public function reclassify(ForeignVatLedgerEntry $entry, ?int $category = null, ?string $supplierCountry = null): ForeignVatLedgerEntry
    {
        if (! $entry->status->isOpen() && $entry->status !== LedgerStatus::NonRecoverableExpensed) {
            throw ForeignVatException::because('ledger_entry_locked');
        }

        $role = $entry->source_type === SourceType::PassiveInvoice ? PostingAccountRole::InputVat : PostingAccountRole::PurchaseExpense;
        $facts = [
            'tenant_id' => (string) $entry->tenant_id,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'document_number' => $entry->document_number,
            'document_date' => $entry->document_date->format('Y-m-d'),
            'supplier_name' => $entry->supplier_name,
            'supplier_vat_number' => $entry->supplier_vat_number,
            'supplier_country_code' => $supplierCountry ?? $entry->supplier_country_code,
            'expense_category_code' => $category ?? $entry->expense_category_code,
            'net_amount' => (string) $entry->net_amount,
            'foreign_vat_rate' => (string) $entry->foreign_vat_rate,
            'foreign_vat_amount' => (string) $entry->foreign_vat_amount,
            'currency_code' => $entry->currency_code,
            'exchange_rate_to_base' => (string) $entry->exchange_rate_to_base,
            'credit_role' => $role,
        ];

        // An expense line that waits for the payout posting stays unposted; every other item is (re)booked, which also retries a failed posting.
        $post = $entry->source_type !== SourceType::ExpenseReport || $entry->journal_entry_id !== null;

        return $this->persist($facts, $entry, $post);
    }

    /**
     * What the refund matrix does with an amount of VAT of a country, without recording anything (the badge of the screens).
     *
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, string $country, int $category, string $vatAmount): array
    {
        $home = $this->rules->homeCountry($tenantId);
        $country = strtoupper($country);
        $vat = Decimal::round2($vatAmount);
        $base = ['home_country' => $home, 'country' => $country, 'expense_category_code' => $category, 'vat_amount' => $vat];

        if ($country === $home) {
            return $base + ['applies' => false, 'reason' => 'domestic', 'has_reciprocity' => false, 'regime_type' => null, 'deductible_percentage' => '0.00', 'recoverable_vat_amount' => '0.00', 'non_recoverable_vat_amount' => $vat, 'currency_code' => null];
        }

        $rule = $this->rules->find($tenantId, $home, $country, $category);

        if ($rule === null || ! $rule->has_reciprocity) {
            return $base + ['applies' => true, 'reason' => $rule === null ? 'no_rule' : 'no_reciprocity', 'has_reciprocity' => false, 'regime_type' => $rule?->regime_type->value, 'deductible_percentage' => '0.00', 'recoverable_vat_amount' => '0.00', 'non_recoverable_vat_amount' => $vat, 'currency_code' => $rule?->currency_code];
        }

        $split = $this->calculator->split($vat, (string) $rule->deductible_percentage);

        return $base + [
            'applies' => true,
            'reason' => null,
            'has_reciprocity' => true,
            'regime_type' => $rule->regime_type->value,
            'deductible_percentage' => (string) $rule->deductible_percentage,
            'recoverable_vat_amount' => $split['recoverable'],
            'non_recoverable_vat_amount' => $split['non_recoverable'],
            'currency_code' => $rule->currency_code,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function record(array $facts, bool $post = true): ?ForeignVatLedgerEntry
    {
        $tenantId = (string) $facts['tenant_id'];
        $country = strtoupper((string) $facts['supplier_country_code']);

        if ($country === '' || $country === $this->rules->homeCountry($tenantId) || ! Decimal::isPositive((string) $facts['foreign_vat_amount'])) {
            return null;
        }

        $existing = $facts['source_id'] === null ? null : ForeignVatLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $facts['source_type']->value)
            ->where('source_id', $facts['source_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null && ! $existing->status->isOpen() && $existing->status !== LedgerStatus::NonRecoverableExpensed) {
            return $existing;
        }

        return $this->persist($facts, $existing, $post);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function persist(array $facts, ?ForeignVatLedgerEntry $existing, bool $post): ForeignVatLedgerEntry
    {
        $tenantId = (string) $facts['tenant_id'];
        $home = $this->rules->homeCountry($tenantId);
        $country = strtoupper((string) $facts['supplier_country_code']);
        $category = (int) $facts['expense_category_code'];
        $rule = $this->rules->find($tenantId, $home, $country, $category);
        $percentage = $rule !== null && $rule->has_reciprocity ? (string) $rule->deductible_percentage : '0.00';
        $split = $this->calculator->split((string) $facts['foreign_vat_amount'], $percentage);
        $rate = (string) $facts['exchange_rate_to_base'];
        $baseTotal = $this->calculator->toBase((string) $facts['foreign_vat_amount'], $rate);
        $baseRecoverable = $this->calculator->toBase($split['recoverable'], $rate);
        $date = (string) $facts['document_date'];

        $values = [
            'document_number' => $facts['document_number'],
            'document_date' => $date,
            'supplier_name' => $facts['supplier_name'],
            'supplier_vat_number' => $facts['supplier_vat_number'],
            'supplier_country_code' => $country,
            'expense_category_code' => $category,
            'net_amount' => $facts['net_amount'],
            'foreign_vat_rate' => $facts['foreign_vat_rate'],
            'foreign_vat_amount' => Decimal::round2((string) $facts['foreign_vat_amount']),
            'currency_code' => strtoupper((string) $facts['currency_code']),
            'exchange_rate_to_base' => $rate,
            'base_currency_foreign_vat_amount' => $baseTotal,
            'eligible_percentage' => $percentage,
            'recoverable_vat_amount' => $split['recoverable'],
            'non_recoverable_vat_amount' => $split['non_recoverable'],
            'base_recoverable_vat_amount' => $baseRecoverable,
            'fiscal_year' => (int) substr($date, 0, 4),
            'fiscal_quarter' => Jurisdictions::quarterOf($date),
        ];

        return DB::transaction(function () use ($facts, $existing, $values, $post, $tenantId): ForeignVatLedgerEntry {
            $entry = $existing ?? new ForeignVatLedgerEntry(['tenant_id' => $tenantId, 'source_type' => $facts['source_type'], 'source_id' => $facts['source_id']]);

            // The posted entry no longer matches the figures: reverse it, it is booked again below.
            if ($entry->journal_entry_id !== null && $this->changes($entry, $values)) {
                $this->poster->reverse((string) $entry->journal_entry_id, 'Foreign VAT reclassified', null);
                $entry->journal_entry_id = null;
            }

            $entry->fill($values);
            $entry->status = Decimal::isPositive($values['recoverable_vat_amount']) ? LedgerStatus::PendingReview : LedgerStatus::NonRecoverableExpensed;
            $entry->posting_error = null;
            $entry->save();

            if ($post && $entry->journal_entry_id === null) {
                $this->book($entry, $facts['credit_role']);
            } elseif ($entry->journal_entry_id !== null) {
                $entry->status = Decimal::isPositive($values['recoverable_vat_amount']) ? LedgerStatus::EligibleAccumulating : LedgerStatus::NonRecoverableExpensed;
                $entry->save();
            }

            return $entry->refresh();
        });
    }

    private function book(ForeignVatLedgerEntry $entry, PostingAccountRole $role): void
    {
        try {
            $journal = $this->poster->postRecognition($entry, $role);
            $entry->journal_entry_id = $journal?->id;
            $entry->transitory_account_id = Decimal::isPositive((string) $entry->base_recoverable_vat_amount) ? $this->accounts->receivable((string) $entry->tenant_id)->id : null;
            $entry->status = Decimal::isPositive((string) $entry->recoverable_vat_amount) ? LedgerStatus::EligibleAccumulating : LedgerStatus::NonRecoverableExpensed;
            $entry->save();
        } catch (ForeignVatException $exception) {
            $entry->forceFill(['posting_error' => $exception->errorCode()])->save();
        } catch (Throwable $exception) {
            $entry->forceFill(['posting_error' => mb_substr($exception->getMessage(), 0, 250)])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function changes(ForeignVatLedgerEntry $entry, array $values): bool
    {
        return Decimal::compare((string) $entry->base_recoverable_vat_amount, (string) $values['base_recoverable_vat_amount']) !== 0
            || Decimal::compare((string) $entry->base_currency_foreign_vat_amount, (string) $values['base_currency_foreign_vat_amount']) !== 0
            || $entry->supplier_country_code !== $values['supplier_country_code'];
    }
}
