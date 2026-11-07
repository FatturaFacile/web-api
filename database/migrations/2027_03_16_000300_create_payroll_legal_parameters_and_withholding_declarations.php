<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Per-year legal parameters by topic, each with its source, withholding tax statements and the frontier worker data of
     * the payroll profile. Only values read in an official source are seeded here; a value that could not be verified
     * stays null and the calculation that needs it refuses to run until the company or an operator sets it.
     */
    public function up(): void
    {
        Schema::create('payroll_legal_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('topic', 40);
            $table->unsignedSmallInteger('year');
            $table->jsonb('parameters');
            $table->text('source');
            $table->timestamps();

            $table->index(['topic', 'year']);
        });

        DB::statement('CREATE UNIQUE INDEX payroll_legal_parameters_generic ON payroll_legal_parameters (topic, year) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX payroll_legal_parameters_company ON payroll_legal_parameters (tenant_id, topic, year) WHERE tenant_id IS NOT NULL');

        Schema::table('payroll_employee_profiles', function (Blueprint $table): void {
            $table->text('birth_place')->nullable();
            $table->text('italian_tax_code')->nullable();
            $table->string('frontier_regime', 20)->nullable();
            $table->date('frontier_status_valid_from')->nullable();
            $table->boolean('frontier_commune_in_zone')->nullable();
            $table->boolean('frontier_returns_daily')->default(true);
            $table->string('ccl_zone', 30)->nullable();
        });

        Schema::table('payroll_swiss_settings', function (Blueprint $table): void {
            $table->jsonb('withholding_debtor_numbers')->nullable();
        });

        Schema::create('payroll_frontier_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('nights_away');
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'year']);
        });

        Schema::create('payroll_withholding_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('canton', 2);
            $table->unsignedSmallInteger('year');
            $table->string('period_type', 10);
            $table->unsignedSmallInteger('period');
            $table->text('content');
            $table->string('content_hash', 64);
            $table->decimal('total_tax', 18, 2);
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'canton', 'year', 'period_type', 'period'], 'payroll_withholding_statements_period');
        });

        $this->insertGenericRows();
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_withholding_statements');
        Schema::dropIfExists('payroll_frontier_years');

        Schema::table('payroll_swiss_settings', function (Blueprint $table): void {
            $table->dropColumn('withholding_debtor_numbers');
        });

        Schema::table('payroll_employee_profiles', function (Blueprint $table): void {
            $table->dropColumn(['birth_place', 'italian_tax_code', 'frontier_regime', 'frontier_status_valid_from', 'frontier_commune_in_zone', 'frontier_returns_daily', 'ccl_zone']);
        });

        Schema::dropIfExists('payroll_legal_parameters');
    }

    /**
     * Each row states the document it was read from. Dates and figures are copied from those documents.
     */
    private function insertGenericRows(): void
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
};
