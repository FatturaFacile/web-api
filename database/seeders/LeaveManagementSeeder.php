<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\Enums\LeaveUnitMode;
use Modules\LeaveManagement\Domain\Enums\SundayHolidayRule;
use Modules\LeaveManagement\Models\HolidayCalendar;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;
use Modules\LeaveManagement\Services\HolidayCatalogService;

/**
 * Seeds default CH (TI, ZH, GE, VD) and IT (Milan Sant'Ambrogio, Rome SS. Pietro e Paolo)
 * holiday calendars and CH/IT LeaveEntitlementPolicy records per tenant.
 */
final class LeaveManagementSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(HolidayCatalogService::class);
        $year = (int) date('Y');

        foreach (Tenant::query()->cursor() as $tenant) {
            $tenantId = (string) $tenant->id;
            $this->seedCalendarsForTenant($tenantId, $year, $catalog);
            $this->seedPoliciesForTenant($tenantId);
        }
    }

    public function seedForTenant(string $tenantId, ?int $year = null): void
    {
        $catalog = app(HolidayCatalogService::class);
        $targetYear = $year ?? (int) date('Y');
        $this->seedCalendarsForTenant($tenantId, $targetYear, $catalog);
        $this->seedPoliciesForTenant($tenantId);
    }

    private function seedCalendarsForTenant(string $tenantId, int $year, HolidayCatalogService $catalog): void
    {
        $definitions = [
            ['code' => 'CH_TI', 'name' => 'Svizzera — Canton Ticino (TI)', 'country' => 'CH', 'canton' => 'TI', 'municipality' => null, 'patron_date' => null, 'patron_name' => null],
            ['code' => 'CH_ZH', 'name' => 'Svizzera — Kanton Zürich (ZH)', 'country' => 'CH', 'canton' => 'ZH', 'municipality' => null, 'patron_date' => null, 'patron_name' => null],
            ['code' => 'CH_GE', 'name' => 'Suisse — Canton de Genève (GE)', 'country' => 'CH', 'canton' => 'GE', 'municipality' => null, 'patron_date' => null, 'patron_name' => null],
            ['code' => 'CH_VD', 'name' => 'Suisse — Canton de Vaud (VD)', 'country' => 'CH', 'canton' => 'VD', 'municipality' => null, 'patron_date' => null, 'patron_name' => null],
            ['code' => 'IT_MI', 'name' => "Italia — Milano (Sant'Ambrogio)", 'country' => 'IT', 'canton' => 'MI', 'municipality' => 'Milano', 'patron_date' => '12-07', 'patron_name' => "Sant'Ambrogio"],
            ['code' => 'IT_RM', 'name' => 'Italia — Roma (SS. Pietro e Paolo)', 'country' => 'IT', 'canton' => 'RM', 'municipality' => 'Roma', 'patron_date' => '06-29', 'patron_name' => 'SS. Pietro e Paolo'],
        ];

        foreach ($definitions as $def) {
            $exists = HolidayCalendar::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $def['code'])
                ->where('year', $year)
                ->exists();

            if ($exists) {
                continue;
            }

            $holidays = array_map(
                static fn ($entry): array => $entry->toArray(),
                $catalog->generateForJurisdiction(
                    country: $def['country'],
                    year: $year,
                    cantonOrProvinceCode: $def['canton'],
                    municipalityName: $def['municipality'],
                    patronSaintMonthDay: $def['patron_date'],
                    patronSaintName: $def['patron_name'],
                )
            );

            HolidayCalendar::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => $def['code'],
                'name' => $def['name'],
                'country' => $def['country'],
                'canton_or_province_code' => $def['canton'],
                'municipality_name' => $def['municipality'],
                'municipality_patron_saint_date' => $def['patron_date'],
                'municipality_patron_saint_name' => $def['patron_name'],
                'year' => $year,
                'sunday_holiday_rule' => $def['country'] === 'IT'
                    ? SundayHolidayRule::COMPENSATORY_PAY_OR_DAY->value
                    : SundayHolidayRule::NONE->value,
                'holidays' => $holidays,
                'working_days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'],
                'is_active' => true,
            ]);
        }
    }

    private function seedPoliciesForTenant(string $tenantId): void
    {
        $policies = [
            // Switzerland (CH)
            [
                'code' => 'CH_FERIEN_ART329A',
                'name' => 'Vacanze / Ferien CH (Art. 329a/329b CO)',
                'country' => LeaveCountry::CH->value,
                'leave_type' => LeaveTypeCode::AnnualVacation_Ferie_Ferien->value,
                'ccnl_or_contract_ref' => 'CO Art. 329a / 329b',
                'unit_mode' => LeaveUnitMode::DAYS->value,
                'annual_entitlement' => 20.00,
                'annual_entitlement_under_20' => 25.00,
                'annual_entitlement_over_50' => 25.00,
                'hours_per_workday' => 8.40,
                'accrual_frequency' => 'monthly',
                'prorate_by_fte' => true,
                'prorate_hire_termination' => true,
                'max_carry_over' => 10.00,
                'ch_curtailment_grace_months' => 1,
                'ch_curtailment_enabled' => true,
            ],
            [
                'code' => 'CH_SICK_IJM_KTG',
                'name' => 'Malattia CH (IJM / KTG Wartefrist 30gg)',
                'country' => LeaveCountry::CH->value,
                'leave_type' => LeaveTypeCode::SickLeave_Malattia->value,
                'ccnl_or_contract_ref' => 'CO Art. 324a / IJM-KTG',
                'unit_mode' => LeaveUnitMode::DAYS->value,
                'annual_entitlement' => 0.00,
                'hours_per_workday' => 8.40,
                'accrual_frequency' => 'none',
                'requires_medical_certificate' => true,
                'waiting_period_days' => 30,
                'employer_indemnity_percent' => 100.00,
                'insurer_indemnity_percent' => 80.00,
            ],
            [
                'code' => 'CH_ACCIDENT_SUVA',
                'name' => 'Infortunio CH (LAINF / UVG / SUVA)',
                'country' => LeaveCountry::CH->value,
                'leave_type' => LeaveTypeCode::Accident_Infortunio_SUVA_INAIL->value,
                'ccnl_or_contract_ref' => 'LAINF / UVG Art. 16',
                'unit_mode' => LeaveUnitMode::DAYS->value,
                'annual_entitlement' => 0.00,
                'hours_per_workday' => 8.40,
                'accrual_frequency' => 'none',
                'requires_medical_certificate' => true,
                'waiting_period_days' => 2,
                'employer_indemnity_percent' => 80.00,
                'insurer_indemnity_percent' => 80.00,
            ],
            [
                'code' => 'CH_MILITARY_EO',
                'name' => 'Servizio Militare / Civile CH (IPG / EO / APG)',
                'country' => LeaveCountry::CH->value,
                'leave_type' => LeaveTypeCode::CH_MilitaryCivilService_EO_APG->value,
                'ccnl_or_contract_ref' => 'LIPG / EOG',
                'unit_mode' => LeaveUnitMode::DAYS->value,
                'annual_entitlement' => 0.00,
                'hours_per_workday' => 8.40,
                'accrual_frequency' => 'none',
                'employer_indemnity_percent' => 0.00,
                'insurer_indemnity_percent' => 80.00,
            ],
            // Italy (IT)
            [
                'code' => 'IT_FERIE_CCNL',
                'name' => 'Ferie IT (CCNL — 26gg / Scadenza 18 mesi INPS)',
                'country' => LeaveCountry::IT->value,
                'leave_type' => LeaveTypeCode::AnnualVacation_Ferie_Ferien->value,
                'ccnl_or_contract_ref' => 'D.Lgs. 66/2003 & Circ. INPS 18 mesi',
                'unit_mode' => LeaveUnitMode::DAYS->value,
                'annual_entitlement' => 26.00,
                'hours_per_workday' => 8.00,
                'accrual_frequency' => 'monthly',
                'prorate_by_fte' => true,
                'prorate_hire_termination' => true,
                'min_days_worked_in_month_for_accrual' => 15,
                'expiration_months' => 18,
                'inps_18_month_alert_enabled' => true,
            ],
            [
                'code' => 'IT_ROL_CCNL',
                'name' => 'Permessi ROL IT (Riduzione Orario di Lavoro)',
                'country' => LeaveCountry::IT->value,
                'leave_type' => LeaveTypeCode::IT_PermessiROL->value,
                'ccnl_or_contract_ref' => 'CCNL Commercio / Metalmeccanico',
                'unit_mode' => LeaveUnitMode::HOURS->value,
                'annual_entitlement' => 72.00,
                'hours_per_workday' => 8.00,
                'accrual_frequency' => 'monthly',
                'prorate_by_fte' => true,
                'prorate_hire_termination' => true,
                'min_days_worked_in_month_for_accrual' => 15,
                'expiration_months' => 24,
            ],
            [
                'code' => 'IT_EX_FESTIVITA',
                'name' => 'Permessi Ex-Festività IT (32 ore)',
                'country' => LeaveCountry::IT->value,
                'leave_type' => LeaveTypeCode::IT_ExFestivita->value,
                'ccnl_or_contract_ref' => 'L. 54/1977',
                'unit_mode' => LeaveUnitMode::HOURS->value,
                'annual_entitlement' => 32.00,
                'hours_per_workday' => 8.00,
                'accrual_frequency' => 'monthly',
                'prorate_by_fte' => true,
                'prorate_hire_termination' => true,
                'min_days_worked_in_month_for_accrual' => 15,
                'expiration_months' => 12,
            ],
            [
                'code' => 'IT_MALATTIA_INPS',
                'name' => 'Malattia IT (PUC INPS + Carenza 3gg + Comporto 180gg)',
                'country' => LeaveCountry::IT->value,
                'leave_type' => LeaveTypeCode::SickLeave_Malattia->value,
                'ccnl_or_contract_ref' => 'INPS PUC / Art. 2110 c.c.',
                'unit_mode' => LeaveUnitMode::DAYS->value,
                'annual_entitlement' => 0.00,
                'hours_per_workday' => 8.00,
                'accrual_frequency' => 'none',
                'requires_medical_certificate' => true,
                'requires_inps_puc' => true,
                'waiting_period_days' => 3,
                'employer_indemnity_percent' => 100.00,
                'insurer_indemnity_percent' => 50.00,
                'insurer_indemnity_percent_tier2' => 66.66,
                'comporto_max_days' => 180,
                'comporto_window_months' => 12,
            ],
            [
                'code' => 'IT_LEGGE_104',
                'name' => 'Permessi Legge 104/1992 IT (3gg / 18h mese)',
                'country' => LeaveCountry::IT->value,
                'leave_type' => LeaveTypeCode::IT_Legge104_Permessi->value,
                'ccnl_or_contract_ref' => 'L. 104/1992 Art. 33',
                'unit_mode' => LeaveUnitMode::HOURS->value,
                'annual_entitlement' => 0.00,
                'hours_per_workday' => 8.00,
                'accrual_frequency' => 'none',
                'employer_indemnity_percent' => 0.00,
                'insurer_indemnity_percent' => 100.00,
                'legge_104_monthly_days_limit' => 3.00,
            ],
            [
                'code' => 'IT_INFORTUNIO_INAIL',
                'name' => 'Infortunio sul Lavoro IT (INAIL)',
                'country' => LeaveCountry::IT->value,
                'leave_type' => LeaveTypeCode::Accident_Infortunio_SUVA_INAIL->value,
                'ccnl_or_contract_ref' => 'D.P.R. 1124/1965 INAIL',
                'unit_mode' => LeaveUnitMode::DAYS->value,
                'annual_entitlement' => 0.00,
                'hours_per_workday' => 8.00,
                'accrual_frequency' => 'none',
                'requires_medical_certificate' => true,
                'waiting_period_days' => 4,
                'employer_indemnity_percent' => 60.00,
                'insurer_indemnity_percent' => 60.00,
                'insurer_indemnity_percent_tier2' => 75.00,
            ],
        ];

        foreach ($policies as $row) {
            $exists = LeaveEntitlementPolicy::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $row['code'])
                ->exists();

            if (! $exists) {
                LeaveEntitlementPolicy::withoutGlobalScopes()->create($row + [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'is_paid' => true,
                    'is_active' => true,
                ]);
            }
        }
    }
}
