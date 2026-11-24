<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\SwissVat\Domain\Enums\BezugsteuerStatus;
use Modules\SwissVat\Domain\Enums\SwissVatExemptionType;
use Modules\SwissVat\Domain\Enums\SwissVatMethod;
use Modules\SwissVat\Domain\ValueObjects\QuadraturaCheckResult;
use Modules\SwissVat\Domain\ValueObjects\QuadraturaReport;
use Modules\SwissVat\Domain\ValueObjects\SwissVatBoxLine;
use Modules\SwissVat\Models\SwissVatBezugsteuerEntry;
use Modules\SwissVat\Models\SwissVatConfig;
use Modules\SwissVat\Models\SwissVatReturn;

/**
 * Aggregates ledger/invoice/bill movements, Bezugsteuer, and LIVA Art. 30-33 adjustments into
 * a complete AFC box-by-box reconciliation schedule and runs all 9 accounting quadratura checks (Q1..Q9).
 */
final class SwissVatReconciliationService
{
    private const string TOLERANCE = '0.05';

    public function __construct(
        private readonly SwissVatCalculatorService $calculator,
        private readonly BezugsteuerDetectionService $bezugsteuerService,
        private readonly InputTaxAdjustmentService $adjustmentService,
        private readonly SwissVatRateCatalogService $catalog,
    ) {}

    /**
     * Collects source movements for the period, computes AFC boxes, and builds the quadratura report.
     *
     * @param  array<string, mixed>  $overrides  Optional manual lines/adjustments when testing or simulating
     * @return array{
     *     computed: array<int|string, mixed>,
     *     quadratura: QuadraturaReport,
     *     reconciliation_lines: list<array<string, mixed>>,
     *     box_schedule: list<array<string, mixed>>,
     *     bezugsteuer_ids: list<string>,
     *     adjustment_ids: list<string>,
     * }
     */
    public function reconcilePeriod(
        string $tenantId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?SwissVatConfig $config = null,
        array $overrides = [],
    ): array {
        $method = (string) ($overrides['accounting_method'] ?? ($config === null ? SwissVatMethod::Effective->value : $config->accounting_method));
        $roundingMode = (string) ($overrides['rounding_mode'] ?? ($config === null ? 'chf_005' : $config->rounding_mode));
        $sssPrimary = $overrides['sss_rate_primary'] ?? $config?->sss_rate_primary;
        $sssSecondary = $overrides['sss_rate_secondary'] ?? $config?->sss_rate_secondary;

        $salesLines = $overrides['sales_lines'] ?? $this->readSalesLines($tenantId, $from, $to);
        $purchaseLines = $overrides['purchase_lines'] ?? $this->readPurchaseLines($tenantId, $from, $to);

        $bezEntries = $this->bezugsteuerService->confirmedForPeriod($tenantId, $from, $to);
        $bezPayload = $overrides['bezugsteuer_entries'] ?? $bezEntries->map(static fn (SwissVatBezugsteuerEntry $e): array => [
            'net_amount_chf' => (string) $e->net_amount_chf,
            'tax_amount_chf' => (string) $e->tax_amount_chf,
            'vat_rate_percent' => (string) $e->vat_rate_percent,
            'deductible_input_tax_chf' => (string) $e->deductible_input_tax_chf,
        ])->all();

        $pendingDetectedCount = SwissVatBezugsteuerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('status', BezugsteuerStatus::Detected->value)
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->count();

        $adjTotals = $this->adjustmentService->periodTotals($tenantId, $from, $to);
        $adjustmentsPayload = $overrides['adjustments'] ?? [
            'box_410' => $adjTotals['box_410'],
            'box_415' => $adjTotals['box_415'],
            'box_420' => $adjTotals['box_420'],
        ];

        $computed = $this->calculator->calculate([
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'accounting_method' => $method,
            'rounding_mode' => $roundingMode,
            'sss_rate_primary' => $sssPrimary !== null ? (string) $sssPrimary : null,
            'sss_rate_secondary' => $sssSecondary !== null ? (string) $sssSecondary : null,
            'sales_lines' => $salesLines,
            'purchase_lines' => $purchaseLines,
            'bezugsteuer_entries' => $bezPayload,
            'adjustments' => $adjustmentsPayload,
            'other_flows' => $overrides['other_flows'] ?? [],
            'reductions_box_235' => $overrides['reductions_box_235'] ?? '0',
        ]);

        $tenant = Tenant::query()->find($tenantId);
        $uid = $config?->uid_mwst ?: ($tenant?->uid_number ?: $tenant?->vat_number);

        $quadratura = $this->evaluateQuadratura(
            boxes: $computed['afc_boxes'],
            method: $method,
            uidMwst: $uid ? (string) $uid : null,
            pendingBezugsteuerCount: $pendingDetectedCount,
            periodTo: $to,
        );

        $boxSchedule = $this->buildBoxSchedule($computed['afc_boxes'], $method, $to);

        $reconciliationLines = [
            ['source' => 'sales', 'count' => count($salesLines), 'total_turnover' => $computed['box_200'], 'invoiced_vat' => $computed['invoiced_vat_total']],
            ['source' => 'purchases', 'count' => count($purchaseLines), 'deductible_input_vat' => $computed['afc_boxes']['400']],
            ['source' => 'bezugsteuer', 'count' => count($bezPayload), 'base' => $computed['bezugsteuer_base'], 'tax' => $computed['bezugsteuer_tax']],
            ['source' => 'adjustments', 'count' => $adjTotals['entries']->count(), 'box_410' => $computed['adjustments_box_410'], 'box_415' => $computed['adjustments_box_415'], 'box_420' => $computed['adjustments_box_420']],
        ];

        return [
            'computed' => $computed,
            'quadratura' => $quadratura,
            'reconciliation_lines' => $reconciliationLines,
            'box_schedule' => $boxSchedule,
            'bezugsteuer_ids' => $bezEntries->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
            'adjustment_ids' => $adjTotals['entries']->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
        ];
    }

    /**
     * Runs Q1..Q9 accounting & legal quadratura checks on the AFC box map.
     *
     * @param  array<int|string, mixed>  $boxes
     */
    public function evaluateQuadratura(
        array $boxes,
        string $method,
        ?string $uidMwst,
        int $pendingBezugsteuerCount,
        CarbonImmutable $periodTo,
    ): QuadraturaReport {
        $epoch = $this->catalog->ratesForDate($periodTo);
        $isSss = $method === SwissVatMethod::Saldosteuersatz->value;
        $checks = [];

        // Q1: Box 200 - Box 289 == Box 299
        $b200 = (string) ($boxes['200'] ?? '0.00');
        $b289 = (string) ($boxes['289'] ?? '0.00');
        $b299 = (string) ($boxes['299'] ?? '0.00');
        $expected299 = SwissVatDecimal::round2(SwissVatDecimal::sub($b200, $b289));
        $diffQ1 = SwissVatDecimal::round2(SwissVatDecimal::abs(SwissVatDecimal::sub($expected299, $b299)));
        $checks[] = new QuadraturaCheckResult(
            code: 'Q1_TURNOVER_BALANCE',
            severity: 'blocker',
            passed: SwissVatDecimal::compare($diffQ1, self::TOLERANCE) <= 0,
            expected: $expected299,
            actual: SwissVatDecimal::round2($b299),
            difference: $diffQ1,
            messageKey: 'swiss_vat.quadratura.q1_turnover_balance',
        );

        // Q2: Sum(deductions 220..280) == Box 289
        $dedSum = '0';
        foreach (['220', '221', '225', '230', '235', '280'] as $k) {
            $dedSum = SwissVatDecimal::add($dedSum, (string) ($boxes[$k] ?? '0.00'));
        }
        $expected289 = SwissVatDecimal::round2($dedSum);
        $diffQ2 = SwissVatDecimal::round2(SwissVatDecimal::abs(SwissVatDecimal::sub($expected289, $b289)));
        $checks[] = new QuadraturaCheckResult(
            code: 'Q2_DEDUCTIONS_SUM',
            severity: 'blocker',
            passed: SwissVatDecimal::compare($diffQ2, self::TOLERANCE) <= 0,
            expected: $expected289,
            actual: SwissVatDecimal::round2($b289),
            difference: $diffQ2,
            messageKey: 'swiss_vat.quadratura.q2_deductions_sum',
        );

        // Q3: Taxable turnover 299 matches sum of rate bases (effective) or SSS bases
        $rateBaseSum = '0';
        $taxSum = '0';
        if ($isSss) {
            foreach ($boxes['sss_rates'] ?? [] as $sssRow) {
                $rateBaseSum = SwissVatDecimal::add($rateBaseSum, (string) ($sssRow['base'] ?? '0'));
                $taxSum = SwissVatDecimal::add($taxSum, (string) ($sssRow['tax'] ?? '0'));
            }
        } else {
            foreach ([$epoch['boxes']['standard'], $epoch['boxes']['reduced'], $epoch['boxes']['accommodation']] as $rc) {
                $rateBaseSum = SwissVatDecimal::add($rateBaseSum, (string) ($boxes[$rc]['base'] ?? '0'));
                $taxSum = SwissVatDecimal::add($taxSum, (string) ($boxes[$rc]['tax'] ?? '0'));
            }
        }
        $diffQ3 = SwissVatDecimal::round2(SwissVatDecimal::abs(SwissVatDecimal::sub($b299, $rateBaseSum)));
        $checks[] = new QuadraturaCheckResult(
            code: 'Q3_TAXABLE_BASE_MATCH',
            severity: 'blocker',
            passed: SwissVatDecimal::compare($diffQ3, self::TOLERANCE) <= 0,
            expected: SwissVatDecimal::round2($b299),
            actual: SwissVatDecimal::round2($rateBaseSum),
            difference: $diffQ3,
            messageKey: 'swiss_vat.quadratura.q3_taxable_base_match',
        );

        // Q4: Sum of sales rate taxes + Bezugsteuer == Box 399
        $bezBox = $epoch['boxes']['bezugsteuer'];
        $bezTax = (string) ($boxes[$bezBox]['tax'] ?? '0.00');
        $expected399 = SwissVatDecimal::round2(SwissVatDecimal::add($taxSum, $bezTax));
        $b399 = (string) ($boxes['399'] ?? '0.00');
        $diffQ4 = SwissVatDecimal::round2(SwissVatDecimal::abs(SwissVatDecimal::sub($expected399, $b399)));
        $checks[] = new QuadraturaCheckResult(
            code: 'Q4_OUTPUT_TAX_SUM',
            severity: 'blocker',
            passed: SwissVatDecimal::compare($diffQ4, self::TOLERANCE) <= 0,
            expected: $expected399,
            actual: SwissVatDecimal::round2($b399),
            difference: $diffQ4,
            messageKey: 'swiss_vat.quadratura.q4_output_tax_sum',
        );

        // Q5: Input tax identity: 400 + 405 + 410 - 415 - 420 == 479 (and 479 == 0 under SSS)
        $b400 = (string) ($boxes['400'] ?? '0.00');
        $b405 = (string) ($boxes['405'] ?? '0.00');
        $b410 = (string) ($boxes['410'] ?? '0.00');
        $b415 = (string) ($boxes['415'] ?? '0.00');
        $b420 = (string) ($boxes['420'] ?? '0.00');
        $b479 = (string) ($boxes['479'] ?? '0.00');
        $expected479 = $isSss
            ? '0.00'
            : SwissVatDecimal::round2(SwissVatDecimal::sub(
                SwissVatDecimal::add(SwissVatDecimal::add($b400, $b405), $b410),
                SwissVatDecimal::add($b415, $b420),
            ));
        $diffQ5 = SwissVatDecimal::round2(SwissVatDecimal::abs(SwissVatDecimal::sub($expected479, $b479)));
        $checks[] = new QuadraturaCheckResult(
            code: 'Q5_INPUT_TAX_BALANCE',
            severity: 'blocker',
            passed: SwissVatDecimal::compare($diffQ5, self::TOLERANCE) <= 0,
            expected: $expected479,
            actual: SwissVatDecimal::round2($b479),
            difference: $diffQ5,
            messageKey: 'swiss_vat.quadratura.q5_input_tax_balance',
        );

        // Q6: Net balance 399 - 479 == 500 - 510 (within 0.05 CHF rounding tolerance)
        $b500 = (string) ($boxes['500'] ?? '0.00');
        $b510 = (string) ($boxes['510'] ?? '0.00');
        $expectedNet = SwissVatDecimal::round2(SwissVatDecimal::sub($b399, $b479));
        $actualNet = SwissVatDecimal::round2(SwissVatDecimal::sub($b500, $b510));
        $diffQ6 = SwissVatDecimal::round2(SwissVatDecimal::abs(SwissVatDecimal::sub($expectedNet, $actualNet)));
        $checks[] = new QuadraturaCheckResult(
            code: 'Q6_NET_SETTLEMENT_BALANCE',
            severity: 'blocker',
            passed: SwissVatDecimal::compare($diffQ6, self::TOLERANCE) <= 0,
            expected: $expectedNet,
            actual: $actualNet,
            difference: $diffQ6,
            messageKey: 'swiss_vat.quadratura.q6_net_settlement_balance',
        );

        // Q7: Bezugsteuer consistency (base * rate ~ tax)
        $bezBase = (string) ($boxes[$bezBox]['base'] ?? '0.00');
        $bezRate = (string) ($boxes[$bezBox]['rate'] ?? $epoch['standard']);
        $expectedBezTax = SwissVatDecimal::round05(SwissVatDecimal::div(SwissVatDecimal::mul($bezBase, $bezRate), '100'));
        $diffQ7 = SwissVatDecimal::round2(SwissVatDecimal::abs(SwissVatDecimal::sub($expectedBezTax, $bezTax)));
        $checks[] = new QuadraturaCheckResult(
            code: 'Q7_BEZUGSTEUER_CONSISTENCY',
            severity: 'blocker',
            passed: SwissVatDecimal::compare($diffQ7, '0.10') <= 0,
            expected: $expectedBezTax,
            actual: SwissVatDecimal::round2($bezTax),
            difference: $diffQ7,
            messageKey: 'swiss_vat.quadratura.q7_bezugsteuer_consistency',
        );

        // Q8: UID-MWST configured and valid format
        $hasUid = $uidMwst !== null && preg_match('/^CHE[-.]?\d{3}[.]?\d{3}[.]?\d{3}/', strtoupper(trim($uidMwst))) === 1;
        $checks[] = new QuadraturaCheckResult(
            code: 'Q8_UID_MWST_PRESENT',
            severity: 'warning',
            passed: $hasUid,
            expected: 'CHE-XXX.XXX.XXX MWST',
            actual: $uidMwst ?: '',
            difference: '0.00',
            messageKey: 'swiss_vat.quadratura.q8_uid_mwst_present',
        );

        // Q9: Unreviewed auto-detected Bezugsteuer entries warning
        $checks[] = new QuadraturaCheckResult(
            code: 'Q9_UNCONFIRMED_BEZUGSTEUER',
            severity: 'warning',
            passed: $pendingBezugsteuerCount === 0,
            expected: '0',
            actual: (string) $pendingBezugsteuerCount,
            difference: (string) $pendingBezugsteuerCount,
            messageKey: 'swiss_vat.quadratura.q9_unconfirmed_bezugsteuer',
            params: ['count' => $pendingBezugsteuerCount],
        );

        $hasBlockerFailure = false;
        foreach ($checks as $check) {
            if (! $check->passed && $check->severity === 'blocker') {
                $hasBlockerFailure = true;

                break;
            }
        }

        return new QuadraturaReport(! $hasBlockerFailure, $checks);
    }

    /**
     * Formats the complete AFC Form 050/051 box schedule for UI and reporting.
     *
     * @param  array<int|string, mixed>  $boxes
     * @return list<array<string, mixed>>
     */
    public function buildBoxSchedule(array $boxes, string $method, CarbonImmutable $periodTo): array
    {
        $epoch = $this->catalog->ratesForDate($periodTo);
        $lines = [
            new SwissVatBoxLine('200', 'turnover', 'swiss_vat.boxes.200', (string) ($boxes['200'] ?? '0.00')),
            new SwissVatBoxLine('205', 'turnover', 'swiss_vat.boxes.205', (string) ($boxes['205'] ?? '0.00')),
            new SwissVatBoxLine('220', 'deductions', 'swiss_vat.boxes.220', (string) ($boxes['220'] ?? '0.00')),
            new SwissVatBoxLine('221', 'deductions', 'swiss_vat.boxes.221', (string) ($boxes['221'] ?? '0.00')),
            new SwissVatBoxLine('225', 'deductions', 'swiss_vat.boxes.225', (string) ($boxes['225'] ?? '0.00')),
            new SwissVatBoxLine('230', 'deductions', 'swiss_vat.boxes.230', (string) ($boxes['230'] ?? '0.00')),
            new SwissVatBoxLine('235', 'deductions', 'swiss_vat.boxes.235', (string) ($boxes['235'] ?? '0.00')),
            new SwissVatBoxLine('280', 'deductions', 'swiss_vat.boxes.280', (string) ($boxes['280'] ?? '0.00')),
            new SwissVatBoxLine('289', 'deductions', 'swiss_vat.boxes.289', (string) ($boxes['289'] ?? '0.00')),
            new SwissVatBoxLine('299', 'turnover', 'swiss_vat.boxes.299', (string) ($boxes['299'] ?? '0.00')),
        ];

        if ($method === SwissVatMethod::Saldosteuersatz->value) {
            foreach ($boxes['sss_rates'] ?? [] as $idx => $sssRow) {
                $code = $idx === 0 ? $epoch['boxes']['sss1'] : $epoch['boxes']['sss2'];
                $lines[] = new SwissVatBoxLine(
                    code: $code,
                    section: 'tax_calculation',
                    labelKey: 'swiss_vat.boxes.'.$code,
                    amount: (string) ($sssRow['tax'] ?? '0.00'),
                    baseAmount: (string) ($sssRow['base'] ?? '0.00'),
                    ratePercent: (string) ($sssRow['rate'] ?? '0.00'),
                    taxAmount: (string) ($sssRow['tax'] ?? '0.00'),
                    epochBoxAlias: isset($sssRow['alias']) ? (string) $sssRow['alias'] : null,
                );
            }
        } else {
            foreach ([
                $epoch['boxes']['standard'],
                $epoch['boxes']['reduced'],
                $epoch['boxes']['accommodation'],
            ] as $rateBox) {
                $row = $boxes[$rateBox] ?? ['base' => '0.00', 'rate' => '0.00', 'tax' => '0.00', 'alias' => null];
                $lines[] = new SwissVatBoxLine(
                    code: $rateBox,
                    section: 'tax_calculation',
                    labelKey: 'swiss_vat.boxes.'.$rateBox,
                    amount: (string) ($row['tax'] ?? '0.00'),
                    baseAmount: (string) ($row['base'] ?? '0.00'),
                    ratePercent: (string) ($row['rate'] ?? '0.00'),
                    taxAmount: (string) ($row['tax'] ?? '0.00'),
                    epochBoxAlias: isset($row['alias']) ? (string) $row['alias'] : null,
                );
            }
        }

        $bezBox = $epoch['boxes']['bezugsteuer'];
        $bezRow = $boxes[$bezBox] ?? ['base' => '0.00', 'rate' => $epoch['standard'], 'tax' => '0.00', 'alias' => null];
        $lines[] = new SwissVatBoxLine(
            code: $bezBox,
            section: 'tax_calculation',
            labelKey: 'swiss_vat.boxes.'.$bezBox,
            amount: (string) ($bezRow['tax'] ?? '0.00'),
            baseAmount: (string) ($bezRow['base'] ?? '0.00'),
            ratePercent: (string) ($bezRow['rate'] ?? $epoch['standard']),
            taxAmount: (string) ($bezRow['tax'] ?? '0.00'),
            epochBoxAlias: isset($bezRow['alias']) ? (string) $bezRow['alias'] : null,
        );

        $lines[] = new SwissVatBoxLine('399', 'tax_calculation', 'swiss_vat.boxes.399', (string) ($boxes['399'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('400', 'input_tax', 'swiss_vat.boxes.400', (string) ($boxes['400'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('405', 'input_tax', 'swiss_vat.boxes.405', (string) ($boxes['405'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('410', 'input_tax', 'swiss_vat.boxes.410', (string) ($boxes['410'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('415', 'input_tax', 'swiss_vat.boxes.415', (string) ($boxes['415'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('420', 'input_tax', 'swiss_vat.boxes.420', (string) ($boxes['420'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('479', 'input_tax', 'swiss_vat.boxes.479', (string) ($boxes['479'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('500', 'settlement', 'swiss_vat.boxes.500', (string) ($boxes['500'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('510', 'settlement', 'swiss_vat.boxes.510', (string) ($boxes['510'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('900', 'other_flows', 'swiss_vat.boxes.900', (string) ($boxes['900'] ?? '0.00'));
        $lines[] = new SwissVatBoxLine('910', 'other_flows', 'swiss_vat.boxes.910', (string) ($boxes['910'] ?? '0.00'));

        return array_map(static fn (SwissVatBoxLine $l): array => $l->toArray(), $lines);
    }

    public function buildScheduleForReturn(SwissVatReturn $vatReturn): array
    {
        return $this->buildBoxSchedule($vatReturn->afc_boxes, (string) $vatReturn->accounting_method, $vatReturn->period_to);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readSalesLines(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = [];

        if (! Schema::hasTable('invoices') || ! Schema::hasTable('invoice_items')) {
            return $lines;
        }

        $rows = DB::table('invoice_items as ii')
            ->join('invoices as i', 'ii.invoice_id', '=', 'i.id')
            ->leftJoin('swiss_vat_tax_codes as stc', 'ii.tax_code_id', '=', 'stc.id')
            ->where('i.tenant_id', $tenantId)
            ->whereNull('i.deleted_at')
            ->whereNull('ii.deleted_at')
            ->whereDate('i.date', '>=', $from->toDateString())
            ->whereDate('i.date', '<=', $to->toDateString())
            ->get([
                'i.invoice_type',
                'i.exchange_rate',
                'ii.line_total',
                'ii.tax_rate',
                'stc.exemption_type',
                'stc.afc_box',
                'stc.rate_percent as stc_rate',
                'stc.rate_type',
            ]);

        foreach ($rows as $row) {
            $fx = $row->exchange_rate !== null && SwissVatDecimal::isPositive((string) $row->exchange_rate)
                ? (string) $row->exchange_rate
                : '1';
            $netChf = SwissVatDecimal::round2(SwissVatDecimal::mul((string) $row->line_total, $fx));
            $rate = $row->stc_rate !== null ? (string) $row->stc_rate : (string) $row->tax_rate;

            $lines[] = [
                'net_amount' => $netChf,
                'tax_rate' => $rate,
                'exemption_type' => $row->exemption_type ?? SwissVatExemptionType::None->value,
                'afc_box' => $row->afc_box,
                'sss_rate' => $row->rate_type === 'saldosteuersatz' ? $rate : null,
                'is_credit_note' => stripos((string) $row->invoice_type, 'credit') !== false,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readPurchaseLines(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = [];

        if (! Schema::hasTable('passive_invoices')) {
            return $lines;
        }

        $rows = DB::table('passive_invoices')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['total_net', 'total_tax', 'exchange_rate']);

        foreach ($rows as $row) {
            $tax = (string) ($row->total_tax ?? '0');
            if (! SwissVatDecimal::isPositive($tax)) {
                continue;
            }

            $fx = $row->exchange_rate !== null && SwissVatDecimal::isPositive((string) $row->exchange_rate)
                ? (string) $row->exchange_rate
                : '1';

            $lines[] = [
                'net_amount' => SwissVatDecimal::round2(SwissVatDecimal::mul((string) ($row->total_net ?? '0'), $fx)),
                'input_vat_amount' => SwissVatDecimal::round2(SwissVatDecimal::mul($tax, $fx)),
                'afc_box' => '400',
                'is_deductible' => true,
            ];
        }

        return $lines;
    }
}
