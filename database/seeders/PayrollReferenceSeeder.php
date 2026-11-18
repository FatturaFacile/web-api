<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payroll\Calculation\Italian\ItalianPayrollParameters;
use Modules\Payroll\Calculation\Swiss\SwissPayrollParameters;

/**
 * The generic payroll reference rows (tenant_id null): shared by every company and read-only for them.
 *
 * The rows are written by the payroll migrations and by this seeder, so a database built from the SQLite schema dump
 * (which skips the historical migrations and with them the rows they inserted) gets them too. Idempotent.
 */
class PayrollReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->annualParameters();
        $this->familyAllowanceRates();
        $this->legalParameters();
    }

    /**
     * The parameters of the desktop (2026).
     */
    public function annualParameters(): void
    {
        $now = now();

        $rows = [
            [
                'country' => 'CH',
                'year' => 2026,
                'parameters' => (new SwissPayrollParameters)->toArray(),
                'legal_reference' => 'SwissPayrollRules, SwissSocialInsuranceParameters (desktop)',
            ],
            [
                'country' => 'IT',
                'year' => 2026,
                'parameters' => (new ItalianPayrollParameters)->toArray(),
                'legal_reference' => 'ItalianPayrollParameters (desktop): Legge di Bilancio 2026, art. 13 TUIR, L. 207/2024, circolare INPS n. 6/2026',
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('payroll_annual_parameters')
                ->whereNull('tenant_id')->where('country', $row['country'])->where('year', $row['year'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('payroll_annual_parameters')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'country' => $row['country'],
                'year' => $row['year'],
                'parameters' => json_encode($row['parameters'], JSON_THROW_ON_ERROR),
                'legal_reference' => $row['legal_reference'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * The federal minimum family allowances from 2025, which the desktop creates as editable starting values.
     */
    public function familyAllowanceRates(): void
    {
        $now = now();

        foreach (['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH'] as $canton) {
            $exists = DB::table('payroll_family_allowance_rates')
                ->whereNull('tenant_id')->where('canton', $canton)->where('valid_from', '2025-01-01')->exists();

            if ($exists) {
                continue;
            }

            DB::table('payroll_family_allowance_rates')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'canton' => $canton,
                'valid_from' => '2025-01-01',
                'child_allowance' => '215.00',
                'education_allowance' => '268.00',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Each row states the document it was read from. Dates and figures are copied from those documents.
     */
    public function legalParameters(): void
    {
        $rows = [
            [
                'topic' => 'frontier_italy',
                'year' => 2024,
                'source' => 'Foglio informativo e FAQ AFC/GR/TI/VS, Nuovo accordo tra la Svizzera e l\'Italia sull\'imposizione dei lavoratori frontalieri, v. 31.10.2023 (accordo del 23.12.2020, in vigore dal 17.7.2023, applicato dal 1.1.2024): definizione art. 2 lett. b, FAQ 2.4, 2.6, 2.7, 3.1, 3.4, 5.1, 5.2.',
                'parameters' => [
                    'first_application' => '2024-01-01',
                    'transitional_from' => '2018-12-31',
                    'transitional_to' => '2023-07-17',
                    'border_cantons' => ['GR', 'TI', 'VS'],
                    'old_regime_tariffs' => ['A', 'B', 'C', 'H'],
                    'new_regime_tariffs' => ['R', 'S', 'T', 'U'],
                    'swiss_share_percent' => '80',
                    'maximum_nights_away_per_year' => 45,
                ],
            ],
            [
                'topic' => 'short_time_work',
                'year' => 2025,
                'source' => 'SECO, Kurzarbeitsentschädigung, Information für Arbeitgeber, Ausgabe 2025 (716.400), domande 8, 10, 13, 16, 17, 21; AVIG SR 837.0. Salario massimo assicurato: Cantone di Lucerna (WAS), pagina Schlechtwetterentschädigung. Il Consiglio federale può estendere temporaneamente la durata massima: verificare su arbeit.swiss.',
                'parameters' => [
                    'compensation_percent' => '80',
                    'minimum_loss_percent' => '10',
                    'waiting_days_per_period' => '1',
                    'maximum_periods' => 12,
                    'framework_months' => 24,
                    'high_loss_percent' => '85',
                    'high_loss_maximum_periods' => 4,
                    'claim_deadline_months' => 3,
                    'notice_days' => 10,
                    'urgent_notice_days' => 3,
                    'maximum_insured_monthly_salary' => '12350',
                ],
            ],
            [
                'topic' => 'bad_weather',
                'year' => 2025,
                'source' => 'Cantone di Lucerna (WAS), pagina Schlechtwetterentschädigung; AVIG art. 42 ss. Giorni di attesa non impostati: la fonte li indica in 2-3 giorni per periodo senza la regola per scegliere, va letto il testo vigente dell\'OADI.',
                'parameters' => [
                    'compensation_percent' => '80',
                    'waiting_days_per_period' => null,
                    'maximum_periods' => 6,
                    'framework_months' => 24,
                    'claim_deadline_months' => 3,
                    'report_by_day_of_next_month' => 5,
                    'maximum_insured_monthly_salary' => '12350',
                ],
            ],
            [
                'topic' => 'withholding_tax_statement',
                'year' => 2021,
                'source' => 'Cantone Ticino, Direttiva cantonale in ambito imposte alla fonte 2021 v. 1.1 (provvigione di riscossione, trasmissione entro il 31 gennaio); Conferenza svizzera delle imposte, Nota ELM-QST v. 5.0 del 4.12.2020, cap. 2.3 e 10 (conteggio mensile, chiusura trimestrale in IFonte).',
                'parameters' => [
                    'collection_commission_percent' => ['paper' => '1', 'electronic' => '2'],
                    'annual_statement_due' => '01-31',
                    'closing_period_by_canton' => ['TI' => 'quarterly'],
                ],
            ],
        ];

        $now = now();

        foreach ($rows as $row) {
            $exists = DB::table('payroll_legal_parameters')
                ->whereNull('tenant_id')->where('topic', $row['topic'])->where('year', $row['year'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('payroll_legal_parameters')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => null,
                'topic' => $row['topic'],
                'year' => $row['year'],
                'parameters' => json_encode($row['parameters'], JSON_THROW_ON_ERROR),
                'source' => $row['source'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
