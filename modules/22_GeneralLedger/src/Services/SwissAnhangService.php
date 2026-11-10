<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\SwissStatutoryNote;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Builds and updates the Swiss statutory notes to the financial statements (Anhang, CO Art. 959c).
 *
 * Combines structured disclosures (FTE band, valuation principles, pledged assets, leasing commitments,
 * pension fund liabilities, fire insurance values, subsequent events, auditor fees) with auto-populated
 * general ledger figures (assets, liabilities, equity, net income, and FX closing rates applied).
 */
final readonly class SwissAnhangService
{
    public const ALLOWED_FTE_BANDS = ['up_to_10', 'up_to_50', 'up_to_250', 'over_250'];

    public function __construct(
        private LedgerReportService $reports,
    ) {}

    /**
     * Retrieves the Swiss Anhang report for a fiscal year, auto-deriving ledger figures and BVG liabilities.
     *
     * @return array<string, mixed>
     */
    public function getForFiscalYear(string $tenantId, string $fiscalYearId): array
    {
        $fiscalYear = FiscalYear::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($fiscalYearId);

        $note = SwissStatutoryNote::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereNull('deleted_at')
            ->first();

        return $this->present($tenantId, $fiscalYear, $note);
    }

    /**
     * Creates or updates the Swiss Anhang disclosures for a fiscal year.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function upsertForFiscalYear(string $tenantId, string $fiscalYearId, array $data, ?string $actorId = null): array
    {
        $fiscalYear = FiscalYear::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($fiscalYearId);

        $note = DB::transaction(function () use ($tenantId, $fiscalYear, $data, $actorId): SwissStatutoryNote {
            $existing = SwissStatutoryNote::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('fiscal_year_id', $fiscalYear->id)
                ->lockForUpdate()
                ->first();

            $note = $existing ?? new SwissStatutoryNote([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $fiscalYear->company_id,
                'fiscal_year_id' => (string) $fiscalYear->id,
            ]);

            if ($note->trashed()) {
                $note->restore();
            }

            if (array_key_exists('fte_band', $data) && $data['fte_band'] !== null) {
                $note->fte_band = (string) $data['fte_band'];
            } elseif (! $note->exists && $note->fte_band === null) {
                $note->fte_band = 'up_to_10';
            }

            if (array_key_exists('valuation_principles', $data)) {
                $val = $data['valuation_principles'];
                $note->valuation_principles = is_array($val) ? json_encode($val, JSON_THROW_ON_ERROR) : ($val !== null ? (string) $val : null);
            }

            if (array_key_exists('pledged_assets_amount', $data)) {
                $note->setAttribute(
                    'pledged_assets_amount',
                    $data['pledged_assets_amount'] !== null ? Decimal::normalize((string) $data['pledged_assets_amount']) : '0.0000'
                );
            }

            if (array_key_exists('pledged_assets_details', $data)) {
                $note->pledged_assets_details = $data['pledged_assets_details'] !== null ? (string) $data['pledged_assets_details'] : null;
            }

            if (array_key_exists('leasing_commitments_amount', $data)) {
                $note->setAttribute(
                    'leasing_commitments_amount',
                    $data['leasing_commitments_amount'] !== null ? Decimal::normalize((string) $data['leasing_commitments_amount']) : '0.0000'
                );
            }

            if (array_key_exists('leasing_commitments_details', $data)) {
                $note->leasing_commitments_details = $data['leasing_commitments_details'] !== null ? (string) $data['leasing_commitments_details'] : null;
            }

            if (array_key_exists('pension_fund_liabilities_amount', $data)) {
                $note->setAttribute(
                    'pension_fund_liabilities_amount',
                    $data['pension_fund_liabilities_amount'] !== null ? Decimal::normalize((string) $data['pension_fund_liabilities_amount']) : null
                );
            }

            if (array_key_exists('pension_fund_liabilities_details', $data)) {
                $note->pension_fund_liabilities_details = $data['pension_fund_liabilities_details'] !== null ? (string) $data['pension_fund_liabilities_details'] : null;
            }

            if (array_key_exists('fire_insurance_values', $data)) {
                $note->fire_insurance_values = is_array($data['fire_insurance_values']) ? $data['fire_insurance_values'] : null;
            }

            if (array_key_exists('subsequent_events', $data)) {
                $note->subsequent_events = $data['subsequent_events'] !== null ? (string) $data['subsequent_events'] : null;
            }

            if (array_key_exists('auditor_fees_audit', $data)) {
                $note->setAttribute(
                    'auditor_fees_audit',
                    $data['auditor_fees_audit'] !== null ? Decimal::normalize((string) $data['auditor_fees_audit']) : '0.0000'
                );
            }

            if (array_key_exists('auditor_fees_other', $data)) {
                $note->setAttribute(
                    'auditor_fees_other',
                    $data['auditor_fees_other'] !== null ? Decimal::normalize((string) $data['auditor_fees_other']) : '0.0000'
                );
            }

            if (array_key_exists('extra_disclosures', $data)) {
                $note->extra_disclosures = is_array($data['extra_disclosures']) ? $data['extra_disclosures'] : null;
            }

            if ($actorId !== null) {
                $note->updated_by = $actorId;
            }

            $note->save();

            return $note->refresh();
        });

        return $this->present($tenantId, $fiscalYear, $note);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $tenantId, FiscalYear $fiscalYear, ?SwissStatutoryNote $note): array
    {
        $startDate = CarbonImmutable::parse((string) $fiscalYear->start_date);
        $endDate = CarbonImmutable::parse((string) $fiscalYear->end_date);
        $accountingCurrency = TenantAccountingCurrency::of($tenantId);

        $balanceSheet = $this->reports->balanceSheet($tenantId, $endDate);
        $incomeStatement = $this->reports->incomeStatement($tenantId, $startDate, $endDate);

        $liabilityAccounts = $balanceSheet['sections']['liability']['accounts'] ?? [];
        $derivedPensionLiability = $this->derivePensionFundLiability($liabilityAccounts);
        $effectivePensionLiability = $note?->pension_fund_liabilities_amount !== null
            ? Decimal::normalize((string) $note->pension_fund_liabilities_amount)
            : $derivedPensionLiability;

        $fxRates = $this->fxRatesApplied($accountingCurrency, $endDate);
        $bsTotals = $balanceSheet['totals'];
        $isTotals = $incomeStatement['totals'];

        return [
            'fiscal_year_id' => (string) $fiscalYear->id,
            'fiscal_year_code' => (string) ($fiscalYear->year_code ?? ''),
            'fiscal_year_name' => (string) ($fiscalYear->getAttribute('name') ?? $fiscalYear->year_code ?? ''),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'legal_reference' => 'CO Art. 959c',
            'accounting_currency' => $accountingCurrency,
            'fte_band' => $note?->fte_band ?? 'up_to_10',
            'valuation_principles' => $note?->valuation_principles ?? 'Historical cost and prudence / imparity principle pursuant to Swiss Code of Obligations (CO Art. 960 / 960a).',
            'pledged_assets_amount' => Decimal::normalize((string) ($note?->pledged_assets_amount ?? '0')),
            'pledged_assets_details' => $note?->pledged_assets_details,
            'leasing_commitments_amount' => Decimal::normalize((string) ($note?->leasing_commitments_amount ?? '0')),
            'leasing_commitments_details' => $note?->leasing_commitments_details,
            'pension_fund_liabilities_amount' => $effectivePensionLiability,
            'derived_pension_fund_liabilities_amount' => $derivedPensionLiability,
            'pension_fund_liabilities_overridden' => $note?->pension_fund_liabilities_amount !== null,
            'pension_fund_liabilities_details' => $note?->pension_fund_liabilities_details,
            'fire_insurance_values' => $note?->fire_insurance_values ?? [],
            'subsequent_events' => $note?->subsequent_events,
            'auditor_fees_audit' => Decimal::normalize((string) ($note?->auditor_fees_audit ?? '0')),
            'auditor_fees_other' => Decimal::normalize((string) ($note?->auditor_fees_other ?? '0')),
            'extra_disclosures' => $note?->extra_disclosures ?? [],
            'ledger_figures' => [
                'total_assets' => (string) $bsTotals['total_assets'],
                'total_liabilities' => (string) $bsTotals['total_liabilities'],
                'total_equity' => (string) $bsTotals['total_equity_with_result'],
                'net_income' => (string) $isTotals['net_income'],
                'total_revenue' => (string) $isTotals['total_revenue'],
                'total_expenses' => (string) $isTotals['total_expenses'],
                'is_balanced' => (bool) $balanceSheet['is_balanced'],
                'fx_rates_applied' => $fxRates,
            ],
            'updated_by' => $note?->updated_by,
            'updated_at' => $note?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Auto-derives pension / social security liabilities from BVG / LPP / 227x liability accounts at year-end.
     *
     * @param  list<array<string, mixed>>  $liabilityAccounts
     */
    private function derivePensionFundLiability(array $liabilityAccounts): string
    {
        $total = '0.0000';

        foreach ($liabilityAccounts as $account) {
            $code = (string) ($account['account_code'] ?? '');
            $name = strtolower((string) ($account['account_name'] ?? ''));

            $isPensionAccount = str_starts_with($code, '227')
                || str_contains($name, 'bvg')
                || str_contains($name, 'lpp')
                || str_contains($name, 'pension')
                || str_contains($name, 'previdenza')
                || str_contains($name, 'avs')
                || str_contains($name, 'ahv')
                || str_contains($name, 'social');

            if ($isPensionAccount) {
                $amount = (string) ($account['amount'] ?? '0');
                if (bccomp($amount, '0', 4) === 1) {
                    $total = bcadd($total, $amount, 4);
                }
            }
        }

        return $total;
    }

    /**
     * @return list<array{currency: string, closing_rate: string, effective_date: string}>
     */
    private function fxRatesApplied(string $accountingCurrency, CarbonImmutable $endDate): array
    {
        $rows = ExchangeRate::query()
            ->where('source', $accountingCurrency)
            ->whereDate('effective_date', '<=', $endDate->toDateString())
            ->orderByDesc('effective_date')
            ->get(['target', 'rate', 'effective_date']);

        $latestByCurrency = [];
        foreach ($rows as $row) {
            $target = (string) $row->target;
            if (! isset($latestByCurrency[$target])) {
                $latestByCurrency[$target] = [
                    'currency' => $target,
                    'closing_rate' => (string) $row->rate,
                    'effective_date' => CarbonImmutable::parse((string) $row->effective_date)->toDateString(),
                ];
            }
        }

        return array_values($latestByCurrency);
    }
}
