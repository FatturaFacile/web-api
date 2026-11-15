<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\HumanResources\Contracts\Payroll\WithholdingTariffImporterInterface;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;
use Modules\HumanResources\Models\Payroll\WageType;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * System-default Swiss payroll reference data (null tenant, shared by every tenant): wage types, social
 * insurance rate sets for the current and the previous year and sample withholding tax tariffs for TI and ZH.
 *
 * IMPORTANT: the seeded rates, thresholds and tariffs are INDICATIVE. They must be verified against the
 * official BSV/OFAS, SECO, SUVA/insurer and cantonal ESTV publications before any production use, and the
 * insurer-specific rates (UVG, KTG, pension fund) replaced by the tenant's own contracts via tenant rate sets.
 */
final class SwissPayrollReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $tenantContext = app(TenantContext::class);
        $previousTenant = $tenantContext->isExplicit() ? $tenantContext->getTenant() : null;

        // System defaults must be written with a null tenant, whatever context the seeder runs in.
        $tenantContext->setTenant(null);

        try {
            $this->seedWageTypes();

            $currentYear = (int) now()->format('Y');

            foreach ([$currentYear - 1, $currentYear] as $year) {
                $this->seedRateSet($year);
            }

            $this->seedSampleTariffs([$currentYear - 1, $currentYear]);
        } finally {
            if ($previousTenant !== null) {
                $tenantContext->setTenant($previousTenant);
            } else {
                $tenantContext->clear();
            }
        }
    }

    /**
     * Indicative social insurance parameters (values published for 2025, assumed unchanged for 2026).
     *
     * @return array<string, mixed>
     */
    public static function indicativeRates(int $year): array
    {
        return [
            'ahv_iv_eo' => ['employee' => '5.30', 'employer' => '5.30'],
            'ahv_admin_cost' => ['employer' => '0.15'],
            'ahv_contribution_start_age' => 17,
            'reference_age' => 65,
            'ahv_retiree_allowance_monthly' => '1400.00',
            'alv' => [
                'employee' => '1.10',
                'employer' => '1.10',
                'ceiling_annual' => '148200.00',
                'solidarity_employee' => '0.00',
                'solidarity_employer' => '0.00',
            ],
            'bvg' => [
                'entry_threshold' => '22680.00',
                'coordination_deduction' => '26460.00',
                'upper_limit' => '90720.00',
                'min_coordinated_salary' => '3780.00',
                'employer_share' => '50.00',
                'age_credits' => [
                    ['from_age' => 25, 'to_age' => 34, 'rate' => '7.00'],
                    ['from_age' => 35, 'to_age' => 44, 'rate' => '10.00'],
                    ['from_age' => 45, 'to_age' => 54, 'rate' => '15.00'],
                    ['from_age' => 55, 'to_age' => 65, 'rate' => '18.00'],
                ],
            ],
            'uvg' => [
                'max_insured_annual' => '148200.00',
                'classes' => [
                    'A1' => ['bu' => '0.10', 'nbu' => '1.20'],
                    'B1' => ['bu' => '0.70', 'nbu' => '1.40'],
                    'Z0' => ['bu' => '0.10', 'nbu' => '0.00'],
                ],
            ],
            'ktg' => ['employee' => '0.50', 'employer' => '0.50'],
            'fak' => [
                'default' => '1.20',
                'cantons' => ['ZH' => '1.025', 'TI' => '1.50', 'BE' => '1.50', 'GE' => '2.22', 'VD' => '2.35'],
            ],
        ];
    }

    private function seedWageTypes(): void
    {
        $wageTypes = [
            ['code' => '1000', 'name' => 'Monthly salary', 'category' => 'salary', 'elm_code' => 'Salary', 'flags' => [true, true, true, true, true]],
            ['code' => '1005', 'name' => 'Hourly wages', 'category' => 'salary', 'elm_code' => 'Salary', 'flags' => [true, true, true, true, true]],
            ['code' => '1100', 'name' => '13th month salary', 'category' => 'salary', 'elm_code' => 'Salary', 'flags' => [true, true, true, true, true]],
            ['code' => '1200', 'name' => 'Bonus', 'category' => 'allowance', 'elm_code' => 'IrregularIncome', 'flags' => [true, true, false, true, true]],
            ['code' => '1300', 'name' => 'Overtime compensation', 'category' => 'allowance', 'elm_code' => 'Salary', 'flags' => [true, true, false, true, true]],
            ['code' => '2000', 'name' => 'Private use of company car', 'category' => 'benefit_in_kind', 'elm_code' => 'FringeBenefits', 'flags' => [true, true, false, true, true]],
            ['code' => '3000', 'name' => 'Child allowance', 'category' => 'allowance', 'elm_code' => 'FamilyIncome', 'flags' => [false, false, false, false, true]],
            ['code' => '6000', 'name' => 'Expense reimbursement', 'category' => 'expense', 'elm_code' => 'ChargesEffective', 'flags' => [false, false, false, false, false]],
            ['code' => '7000', 'name' => 'Parking deduction', 'category' => 'net_deduction', 'elm_code' => null, 'flags' => [false, false, false, false, false]],
        ];

        foreach ($wageTypes as $index => $definition) {
            [$ahv, $alv, $bvg, $uvg, $qst] = $definition['flags'];

            WageType::query()->whereNull('tenant_id')->updateOrCreate(
                ['tenant_id' => null, 'code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'category' => $definition['category'],
                    'is_ahv_subject' => $ahv,
                    'is_alv_subject' => $alv,
                    'is_bvg_subject' => $bvg,
                    'is_uvg_subject' => $uvg,
                    'is_qst_subject' => $qst,
                    'elm_code' => $definition['elm_code'],
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }

    private function seedRateSet(int $year): void
    {
        PayrollRateSet::query()->whereNull('tenant_id')->updateOrCreate(
            ['tenant_id' => null, 'code' => "CH-{$year}"],
            [
                'name' => "Swiss social insurance rates {$year} (indicative)",
                'year' => $year,
                'valid_from' => "{$year}-01-01",
                'valid_to' => "{$year}-12-31",
                'rates' => self::indicativeRates($year),
                'is_active' => true,
                'notes' => 'Indicative values: verify against official BSV/SECO/ESTV publications before production use.',
            ],
        );
    }

    /**
     * Sample monthly tariffs (A0N single without children, A0Y with church tax) for TI and ZH, imported through
     * the regular CSV importer so the seeder exercises the stable internal format.
     *
     * @param  list<int>  $years
     */
    private function seedSampleTariffs(array $years): void
    {
        $brackets = [
            // [income_from, income_to, rate A0N, rate A0Y]
            ['0.00', '2500.00', '0.00', '0.00'],
            ['2500.05', '4000.00', '2.10', '2.30'],
            ['4000.05', '5500.00', '4.60', '5.00'],
            ['5500.05', '7000.00', '7.10', '7.70'],
            ['7000.05', '9000.00', '9.50', '10.20'],
            ['9000.05', '12000.00', '12.40', '13.30'],
            ['12000.05', '', '16.00', '17.10'],
        ];

        $rows = ['canton,tariff_code,valid_from,valid_to,income_from,income_to,rate_percent,fixed_amount'];

        foreach ($years as $year) {
            // ZH sample rates are 0.40 percentage points lower than TI (hundredths of a percent).
            foreach (['TI' => 0, 'ZH' => -40] as $canton => $hundredthsAdjustment) {
                foreach ($brackets as [$from, $to, $rateWithoutChurch, $rateWithChurch]) {
                    foreach (['A0N' => $rateWithoutChurch, 'A0Y' => $rateWithChurch] as $code => $rate) {
                        $hundredths = max(0, Rappen::fromDecimal($rate) + $hundredthsAdjustment);
                        $rows[] = sprintf('%s,%s,%d-01-01,%d-12-31,%s,%s,%s,0.00', $canton, $code, $year, $year, $from, $to, Rappen::toDecimal($hundredths));
                    }
                }
            }
        }

        app(WithholdingTariffImporterInterface::class)->import(implode("\n", $rows), null, 'seeder');
    }
}
