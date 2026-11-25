<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\ValueObjects\LineFacts;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Models\CorporateCardTransaction;
use Modules\ExpenseManagement\Models\ExpenseCategory;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\Receipt;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Creates and updates expense lines: currency conversion, VAT split, the fiscal assessment of the jurisdiction, the
 * policy and the duplicate receipt checks. The caller recalculates the totals of the report.
 */
final class ExpenseLineService
{
    public function __construct(
        private readonly ExchangeRateResolver $rates,
        private readonly FiscalStrategyRegistry $strategies,
        private readonly PolicyEvaluator $policies,
        private readonly DuplicateDetector $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(ExpenseReport $report, ?ExpenseLine $line, array $data): ExpenseLine
    {
        $category = $this->category((string) $data['category_id']);
        $date = Carbon::parse((string) $data['expense_date']);
        $original = Decimal::normalize($data['original_amount']);
        $currency = strtoupper((string) ($data['original_currency'] ?? $report->currency));
        $fx = $this->rates->resolve($currency, $report->currency, $date, isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null);
        $base = Decimal::mul($original, $fx['rate']);
        [$vatRate, $vatAmount] = $this->vat($data, $original, $base, $fx['rate']);
        $method = PaymentMethod::from((string) ($data['payment_method'] ?? PaymentMethod::EmployeeOutOfPocket->value));
        $receipt = isset($data['receipt_id']) ? Receipt::query()->find($data['receipt_id']) : null;

        if (isset($data['receipt_id']) && $receipt === null) {
            throw new ExpenseException('receipt_not_found', 404);
        }

        $cardTransactionId = $method === PaymentMethod::CorporateCard ? ($data['corporate_card_transaction_id'] ?? null) : null;

        if ($cardTransactionId !== null && ! CorporateCardTransaction::query()->whereKey($cardTransactionId)->exists()) {
            throw new ExpenseException('card_transaction_mismatch');
        }

        $line ??= new ExpenseLine(['tenant_id' => $report->tenant_id, 'expense_report_id' => $report->id]);
        $line->fill([
            'expense_date' => $date->toDateString(),
            'category_id' => $category->id,
            'merchant_name' => (string) $data['merchant_name'],
            'merchant_tax_id' => $this->nullable($data['merchant_tax_id'] ?? null),
            'description' => $this->nullable($data['description'] ?? null),
            'original_amount' => $original,
            'original_currency' => $currency,
            'exchange_rate' => $fx['rate'],
            'exchange_rate_date' => $fx['date'],
            'amount_in_base_currency' => $base,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'payment_method' => $method,
            'corporate_card_transaction_id' => $cardTransactionId,
            'is_traceable_payment' => (bool) ($data['is_traceable_payment'] ?? true),
            'is_reimbursable' => $method === PaymentMethod::EmployeeOutOfPocket,
            'project_id' => $data['project_id'] ?? null,
            'billable_to_customer' => (bool) ($data['billable_to_customer'] ?? false),
            'receipt_id' => $receipt?->id,
            'receipt_document_id' => $receipt?->document_id,
            'receipt_sha256' => $receipt?->sha256,
            'normalized_merchant' => DuplicateDetector::normalizeMerchant((string) $data['merchant_name']),
        ]);

        $this->assess($report, $line, $category);
        $line->save();

        return $line->load('category', 'mileageClaim');
    }

    /**
     * Evaluates the line again (the policy or the other reports may have changed since it was entered).
     */
    public function reassess(ExpenseReport $report, ExpenseLine $line): ExpenseLine
    {
        $this->assess($report, $line, $line->category ?? $this->category($line->category_id));
        $line->save();

        return $line;
    }

    public function assess(ExpenseReport $report, ExpenseLine $line, ExpenseCategory $category): void
    {
        $policy = $this->policies->policyFor($report->jurisdiction);
        $assessment = $this->strategies->for($report->jurisdiction)->assessLine(new LineFacts(
            categoryCode: $category->code,
            isMeal: $category->is_meal,
            isLodging: $category->is_lodging,
            isTransport: $category->is_transport,
            categoryVatRecoverable: $category->vat_recoverable,
            paymentMethod: $line->payment_method,
            declaredTraceable: $line->is_traceable_payment,
            amountInBase: $line->amount_in_base_currency,
            vatAmount: $line->vat_amount,
            merchantTaxId: $line->merchant_tax_id,
            blockNonTraceable: $policy->block_non_traceable ?? false,
        ));

        $violations = [...$assessment->violations, ...$this->policies->evaluate($report, $line, $category, $policy)];

        if ($policy === null || $policy->duplicate_detection) {
            foreach ($this->duplicates->find($line->receipt_sha256, $line->merchant_name, $line->expense_date, $line->amount_in_base_currency, $line->exists ? $line->id : null) as $duplicate) {
                $violations[] = ['code' => $duplicate['code'], 'severity' => $duplicate['severity'], 'params' => $duplicate['params'] + ['matched_line_id' => $duplicate['matched_line_id']]];
            }
        }

        $line->vat_recoverable_amount = $assessment->vatRecoverableAmount;
        $line->is_traceable_payment = $assessment->isTraceable;
        $line->is_taxable_benefit = $assessment->isTaxableBenefit;
        $line->policy_violations = $violations === [] ? null : $violations;
    }

    public function category(string $id): ExpenseCategory
    {
        $category = ExpenseCategory::query()->where('is_active', true)->find($id);

        if ($category === null) {
            throw new ExpenseException('category_not_found', 422);
        }

        return $category;
    }

    public function categoryByCode(string $code): ExpenseCategory
    {
        $category = ExpenseCategory::query()->where('code', $code)->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')->first();

        if ($category === null) {
            throw new ExpenseException('category_not_found', 422);
        }

        return $category;
    }

    /**
     * The VAT is entered in the currency of the receipt: as an amount, or as a rate on a VAT-inclusive total. Stored in the
     * currency of the report.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}
     */
    private function vat(array $data, string $original, string $base, string $fxRate): array
    {
        $rate = Decimal::normalize($data['vat_rate'] ?? '0', 4);

        if (isset($data['vat_amount']) && $data['vat_amount'] !== '') {
            return [$rate, Decimal::mul(Decimal::normalize($data['vat_amount']), $fxRate)];
        }

        if (bccomp($rate, '0', 4) <= 0) {
            return [$rate, '0.00'];
        }

        return [$rate, Decimal::div(Decimal::mul($base, $rate, 4), Decimal::add('100', $rate, 4), 2)];
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
