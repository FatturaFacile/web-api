<?php

declare(strict_types=1);

namespace Database\Seeders;

use Modules\CrisisMonitoring\Models\CncPracticalTestTier;
use Modules\CrisisMonitoring\Models\CndcecAtecoThreshold;
use Modules\CrisisMonitoring\Models\PublicCreditorThreshold;

/**
 * Reference data of the corporate crisis monitoring (Mod 357), global rows (`tenant_id` null) a company can override with its own rows:
 *
 * - the CNDCEC early-warning thresholds of the ATECO sections A to S (percent; the sector groups of the CNDCEC document of 2019);
 * - the Art. 25-novies CCII thresholds of the qualified public creditors, one data row per creditor (and legal form for the AdER);
 * - the feasibility tiers of the CNC practical test (D.M. 21 March 2023).
 *
 * Idempotent: running it again updates the rows by their natural key. The texts of the labels live in the translation dictionaries
 * (`crisisApi.*`), never here. The numbers are the statutory and CNDCEC values at the time of writing: they are data, not code, so they
 * can be corrected without a release.
 */
final class CrisisMonitoringReferenceSeeder extends GlobalDataSeeder
{
    /**
     * CNDCEC sector group => [financial charges / revenue (max), net equity / debt (min), liquidity (min), cash flow / assets (min),
     * tax and social debt / assets (max)], all in percent.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const array GroupThresholds = [
        'agriculture' => ['2.80', '2.30', '92.10', '0.50', '14.60'],
        'industry' => ['3.00', '2.30', '108.00', '0.50', '14.60'],
        'construction' => ['4.60', '2.80', '108.60', '0.30', '15.80'],
        'trade' => ['2.80', '2.70', '109.70', '0.50', '14.60'],
        'transport' => ['3.00', '2.30', '100.00', '0.50', '14.60'],
        'tourism' => ['3.00', '2.30', '100.00', '0.50', '14.60'],
        'services' => ['3.00', '2.30', '100.00', '0.50', '14.60'],
    ];

    /**
     * ATECO section => CNDCEC sector group.
     *
     * @var array<string, string>
     */
    private const array SectionGroups = [
        'A' => 'agriculture', 'B' => 'industry', 'C' => 'industry', 'D' => 'industry', 'E' => 'industry', 'F' => 'construction',
        'G' => 'trade', 'H' => 'transport', 'I' => 'tourism', 'J' => 'services', 'K' => 'services', 'L' => 'services', 'M' => 'services',
        'N' => 'services', 'O' => 'services', 'P' => 'services', 'Q' => 'services', 'R' => 'services', 'S' => 'services',
    ];

    protected function seedGlobalData(): void
    {
        $this->atecoThresholds();
        $this->creditorThresholds();
        $this->cncTiers();
    }

    private function atecoThresholds(): void
    {
        foreach (self::SectionGroups as $section => $group) {
            [$charges, $equity, $liquidity, $cashFlow, $taxSocial] = self::GroupThresholds[$group];

            CndcecAtecoThreshold::query()->updateOrCreate(
                ['tenant_id' => null, 'ateco_section' => $section],
                [
                    'sector_label_key' => 'crisisApi.ateco.'.$section,
                    'cndcec_group' => $group,
                    'financial_charges_revenue_max' => $charges,
                    'equity_total_debt_min' => $equity,
                    'liquidity_ratio_min' => $liquidity,
                    'cash_flow_assets_min' => $cashFlow,
                    'tax_social_debt_assets_max' => $taxSocial,
                    'source_note' => 'CNDCEC early-warning indicators (2019), sector group '.$group,
                    'valid_from' => '2019-10-20',
                    'is_active' => true,
                ],
            );
        }
    }

    private function creditorThresholds(): void
    {
        $rules = [
            ['creditor_code' => 'payroll', 'legal_form' => null, 'overdue_days' => 30, 'percent_threshold' => '50.00', 'percent_basis' => 'monthly_payroll', 'combine' => 'and', 'legal_reference' => 'Art. 25-novies CCII'],
            ['creditor_code' => 'supplier', 'legal_form' => null, 'overdue_days' => 90, 'percent_threshold' => '100.00', 'percent_basis' => 'non_overdue_suppliers', 'combine' => 'and', 'legal_reference' => 'Art. 3 CCII'],
            ['creditor_code' => 'inps', 'legal_form' => null, 'overdue_days' => 90, 'absolute_threshold' => '15000.00', 'absolute_threshold_no_employees' => '5000.00', 'percent_threshold' => '30.00', 'percent_basis' => 'prior_year_contributions', 'combine' => 'and', 'legal_reference' => 'Art. 25-novies c.1 lett. a CCII'],
            ['creditor_code' => 'inail', 'legal_form' => null, 'overdue_days' => 90, 'absolute_threshold' => '5000.00', 'combine' => 'and', 'legal_reference' => 'Art. 25-novies c.1 lett. a CCII'],
            ['creditor_code' => 'ade_vat', 'legal_form' => null, 'overdue_days' => null, 'absolute_threshold' => '5000.00', 'absolute_always_threshold' => '20000.00', 'percent_threshold' => '10.00', 'percent_basis' => 'prior_year_turnover', 'combine' => 'and', 'legal_reference' => 'Art. 25-novies c.1 lett. b CCII'],
            ['creditor_code' => 'ader', 'legal_form' => 'sole_proprietorship', 'overdue_days' => null, 'absolute_threshold' => '100000.00', 'combine' => 'and', 'legal_reference' => 'Art. 25-novies c.1 lett. c CCII'],
            ['creditor_code' => 'ader', 'legal_form' => 'partnership', 'overdue_days' => null, 'absolute_threshold' => '200000.00', 'combine' => 'and', 'legal_reference' => 'Art. 25-novies c.1 lett. c CCII'],
            ['creditor_code' => 'ader', 'legal_form' => 'corporation', 'overdue_days' => null, 'absolute_threshold' => '500000.00', 'combine' => 'and', 'legal_reference' => 'Art. 25-novies c.1 lett. c CCII'],
        ];

        foreach ($rules as $rule) {
            PublicCreditorThreshold::query()->updateOrCreate(
                ['tenant_id' => null, 'creditor_code' => $rule['creditor_code'], 'legal_form' => $rule['legal_form']],
                $rule + [
                    'label_key' => 'crisisApi.creditor.'.$rule['creditor_code'],
                    'description_key' => 'crisisApi.creditor.'.$rule['creditor_code'].'.description',
                    'warning_ratio' => '0.70',
                    'valid_from' => '2022-07-15',
                    'is_active' => true,
                ],
            );
        }
    }

    private function cncTiers(): void
    {
        $tiers = [
            ['code' => 'easily_achievable', 'ratio_up_to' => '3.00', 'status' => 'green', 'sort_order' => 10],
            ['code' => 'achievable_with_measures', 'ratio_up_to' => '6.00', 'status' => 'yellow', 'sort_order' => 20],
            ['code' => 'difficult', 'ratio_up_to' => '9.00', 'status' => 'red', 'sort_order' => 30],
            ['code' => 'not_feasible', 'ratio_up_to' => null, 'status' => 'red', 'sort_order' => 40],
        ];

        foreach ($tiers as $tier) {
            CncPracticalTestTier::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $tier['code']],
                $tier + ['label_key' => 'crisisApi.cnc.tier.'.$tier['code'], 'is_active' => true],
            );
        }
    }
}
