<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;

/**
 * Swiss VAT Annual Reconciliation (Umsatzabstimmung & Vorsteuerabstimmung, Art. 72 LIVA).
 * Compares all periodic VAT settlements of a calendar year against the full-year statutory General Ledger.
 */
final class VatAnnualReconciliationService
{
    public function __construct(
        private readonly VatLedgerReader $ledgerReader,
        private readonly VatAccountResolver $accountResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcileYear(string $tenantId, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $to = CarbonImmutable::create($year, 12, 31)->endOfDay();

        $settlements = VatSettlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->orderBy('period')
            ->get();

        $declaredTurnover = '0.00';
        $declaredTaxableTurnover = '0.00';
        $declaredOutputVat = '0.00';
        $declaredInputVat = '0.00';

        foreach ($settlements as $settlement) {
            $declaredTurnover = Decimal::add($declaredTurnover, (string) $settlement->turnover);
            $declaredTaxableTurnover = Decimal::add($declaredTaxableTurnover, (string) $settlement->taxable_turnover);
            $declaredOutputVat = Decimal::add($declaredOutputVat, (string) $settlement->output_vat_amount);
            $declaredInputVat = Decimal::add($declaredInputVat, (string) $settlement->input_vat_amount);
        }

        $accounts = $this->accountResolver->accountsFor($tenantId, 'CH');
        $snapshot = $this->ledgerReader->read($tenantId, 'CH', $from, $to, $accounts);

        $glRevenue = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.account_id', '=', 'coa.id')
            ->where('je.tenant_id', $tenantId)
            ->whereNull('je.deleted_at')
            ->whereNull('jl.deleted_at')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.ledger_type', 'statutory')
            ->where('je.journal_type', '<>', 'closing')
            ->whereBetween('je.posting_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($q): void {
                $q->whereNull('je.source_document_type')
                    ->orWhereNotIn('je.source_document_type', [
                        VatLedgerReader::SOURCE_SETTLEMENT,
                        VatLedgerReader::SOURCE_SETTLEMENT_PAYMENT,
                    ]);
            })
            ->whereIn('coa.account_type', ['revenue'])
            ->selectRaw('COALESCE(SUM(COALESCE(jl.functional_credit, jl.credit_amount) - COALESCE(jl.functional_debit, jl.debit_amount)), 0) as net_revenue')
            ->value('net_revenue');

        $ledgerTurnover = Decimal::round2((string) ($glRevenue ?? '0'));
        $ledgerOutputVat = Decimal::round2(array_reduce($snapshot->outputByAccount, static fn (string $c, string $v): string => Decimal::add($c, $v), '0'));
        $ledgerInputVat = Decimal::round2(array_reduce($snapshot->inputByAccount, static fn (string $c, string $v): string => Decimal::add($c, $v), '0'));

        $turnoverDelta = Decimal::round2(Decimal::sub($ledgerTurnover, $declaredTurnover));
        $outputVatDelta = Decimal::round2(Decimal::sub($ledgerOutputVat, $declaredOutputVat));
        $inputVatDelta = Decimal::round2(Decimal::sub($ledgerInputVat, $declaredInputVat));

        return [
            'year' => $year,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'settlements_count' => $settlements->count(),
            'umsatzabstimmung' => [
                'ledger_revenue' => $ledgerTurnover,
                'declared_turnover' => Decimal::round2($declaredTurnover),
                'declared_taxable_turnover' => Decimal::round2($declaredTaxableTurnover),
                'turnover_difference' => $turnoverDelta,
                'ledger_output_vat' => $ledgerOutputVat,
                'declared_output_vat' => Decimal::round2($declaredOutputVat),
                'output_vat_difference' => $outputVatDelta,
            ],
            'vorsteuerabstimmung' => [
                'ledger_input_vat' => $ledgerInputVat,
                'declared_input_vat' => Decimal::round2($declaredInputVat),
                'input_vat_difference' => $inputVatDelta,
            ],
            'requires_final_correction_form_055' => ! Decimal::isZero($turnoverDelta)
                || ! Decimal::isZero($outputVatDelta)
                || ! Decimal::isZero($inputVatDelta),
        ];
    }
}
