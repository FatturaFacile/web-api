<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;

/**
 * Reciprocity, deductibility and procedure matrix of the foreign VAT refund (Mod 227, Issue #473), global rows (tenant_id null), idempotent.
 * A company overrides any cell with a row of its own; the engine always prefers the company row.
 *
 *  - IT -> CH: ESTV reciprocity (Art. 107 para. 1 lit. b LIVA, Art. 151-156 OIVA), forms 1222/1223, 30 June, CHF 500 a year, Swiss fiscal
 *    representative and certificate of taxable status required.
 *  - IT -> EU: electronic portal of Directive 2008/9/EC (Art. 38-bis1 DPR 633/72), 30 September, EUR 400 a quarter / EUR 50 a year.
 *  - CH -> IT: Art. 38-ter DPR 633/72, Modello IVA 79 (Centro Operativo di Pescara), 30 September, EUR 400 / EUR 50, certificate of taxable status.
 *  - CH -> DE, FR, AT, ES, NL, BE: Thirteenth Directive 86/560/EEC, same thresholds and certificate.
 *  - A country never refunds itself: CH -> CH and IT -> IT have no row (domestic VAT goes through the VAT settlement).
 *
 * The deductible percentages are configured defaults per Member State and expense category (1 fuel, 2 hiring of means of transport,
 * 3 expenditure on means of transport, 4 road tolls, 5 travel, 6 accommodation, 7 food / drink / restaurants, 8 fairs and exhibitions,
 * 9 luxury / amusement / entertainment, 10 other). They must be reviewed every January against the rules of each country: nothing in the
 * business logic depends on them.
 */
final class ForeignVatRefundReferenceSeeder extends Seeder
{
    /**
     * Deductible percentage by expense category 1..10 for each country VAT is claimed from.
     *
     * @var array<string, list<int>>
     */
    private const array DEDUCTIBILITY = [
        'CH' => [100, 100, 100, 100, 100, 100, 100, 100, 0, 100],
        'IT' => [40, 40, 40, 40, 100, 100, 100, 100, 0, 100],
        'DE' => [100, 100, 100, 100, 100, 100, 100, 100, 0, 100],
        'FR' => [80, 0, 0, 0, 100, 100, 0, 100, 0, 100],
        'AT' => [0, 0, 0, 0, 100, 100, 0, 100, 0, 100],
        'ES' => [50, 50, 50, 50, 100, 100, 100, 100, 0, 100],
        'NL' => [100, 100, 100, 100, 100, 100, 0, 100, 0, 100],
        'BE' => [50, 50, 50, 50, 100, 100, 50, 100, 0, 100],
    ];

    /** @var list<string> */
    private const array SWISS_RATES = ['8.1', '7.7', '3.8', '3.7', '2.6', '2.5'];

    public function run(): void
    {
        foreach ($this->procedures() as $procedure) {
            foreach (self::DEDUCTIBILITY[$procedure['refund']] as $index => $percentage) {
                CountryVatRefundRule::updateOrCreateGlobal(
                    [
                        'home_country_code' => $procedure['home'],
                        'refund_country_code' => $procedure['refund'],
                        'expense_category_code' => $index + 1,
                        'sub_code' => null,
                    ],
                    [
                        'regime_type' => $procedure['regime'],
                        'has_reciprocity' => true,
                        'deductible_percentage' => $percentage,
                        'min_annual_amount' => $procedure['annual'],
                        'min_quarterly_amount' => $procedure['quarterly'],
                        'currency_code' => $procedure['currency'],
                        'filing_deadline_month' => $procedure['month'],
                        'filing_deadline_day' => 30,
                        'vat_rates' => $procedure['rates'],
                        'requires_fiscal_representative' => $procedure['representative'],
                        'requires_taxable_status_certificate' => $procedure['certificate'],
                        'notes_key' => $procedure['notes'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{home: string, refund: string, regime: string, annual: string|null, quarterly: string|null, currency: string, month: int, rates: list<string>|null, representative: bool, certificate: bool, notes: string}>
     */
    private function procedures(): array
    {
        $rows = [
            [
                'home' => 'IT', 'refund' => 'CH', 'regime' => 'CH_ESTV_RECIPROCITY', 'annual' => '500.00', 'quarterly' => null, 'currency' => 'CHF', 'month' => 6,
                'rates' => self::SWISS_RATES, 'representative' => true, 'certificate' => true, 'notes' => 'foreignVat.rule.note.estv_reciprocity',
            ],
            [
                'home' => 'CH', 'refund' => 'IT', 'regime' => 'IT_ART_38_TER', 'annual' => '50.00', 'quarterly' => '400.00', 'currency' => 'EUR', 'month' => 9,
                'rates' => null, 'representative' => false, 'certificate' => true, 'notes' => 'foreignVat.rule.note.it_art_38_ter',
            ],
        ];

        foreach (['DE', 'FR', 'AT', 'ES', 'NL', 'BE'] as $country) {
            $rows[] = [
                'home' => 'CH', 'refund' => $country, 'regime' => 'XIII_DIR_86_560_CEE', 'annual' => '50.00', 'quarterly' => '400.00', 'currency' => 'EUR', 'month' => 9,
                'rates' => null, 'representative' => false, 'certificate' => true, 'notes' => 'foreignVat.rule.note.xiii_directive',
            ];
            $rows[] = [
                'home' => 'IT', 'refund' => $country, 'regime' => 'EU_DIR_2008_9_CE', 'annual' => '50.00', 'quarterly' => '400.00', 'currency' => 'EUR', 'month' => 9,
                'rates' => null, 'representative' => false, 'certificate' => false, 'notes' => 'foreignVat.rule.note.eu_directive',
            ];
        }

        return $rows;
    }
}
