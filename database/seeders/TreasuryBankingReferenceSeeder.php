<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Treasury\Models\F24TaxCode;

/**
 * Global reference data of the treasury banking workspace (#268): the tributo / causale codes of the Modello F24 per
 * section. Idempotent (`updateOrCreate`); descriptions are translation keys served by the API
 * (`treasury.f24.code.<section>.<code>`). The list is the set of codes the workspace offers; a code not listed is
 * refused in the erario, regioni and IMU sections until it is added here after checking the Agenzia delle Entrate tables.
 */
final class TreasuryBankingReferenceSeeder extends Seeder
{
    /**
     * @var array<string, array<int|string, array{period: string}>> section => code => options
     */
    private const array CODES = [
        'erario' => [
            '1001' => ['period' => 'month'], '1038' => ['period' => 'month'], '1040' => ['period' => 'month'],
            '1668' => ['period' => 'year'],
            '2001' => ['period' => 'year'], '2002' => ['period' => 'year'], '2003' => ['period' => 'year'],
            '4001' => ['period' => 'year'], '4033' => ['period' => 'year'], '4034' => ['period' => 'year'],
            '6001' => ['period' => 'year'], '6002' => ['period' => 'year'], '6003' => ['period' => 'year'], '6004' => ['period' => 'year'],
            '6005' => ['period' => 'year'], '6006' => ['period' => 'year'], '6007' => ['period' => 'year'], '6008' => ['period' => 'year'],
            '6009' => ['period' => 'year'], '6010' => ['period' => 'year'], '6011' => ['period' => 'year'], '6012' => ['period' => 'year'],
            '6013' => ['period' => 'year'], '6031' => ['period' => 'year'], '6032' => ['period' => 'year'], '6033' => ['period' => 'year'],
            '6034' => ['period' => 'year'], '6099' => ['period' => 'year'],
        ],
        'regioni' => [
            '3800' => ['period' => 'year'], '3801' => ['period' => 'year'], '3802' => ['period' => 'year'],
            '3812' => ['period' => 'year'], '3813' => ['period' => 'year'], '3843' => ['period' => 'year'], '3844' => ['period' => 'year'],
        ],
        'imu' => [
            '3912' => ['period' => 'year'], '3913' => ['period' => 'year'], '3914' => ['period' => 'year'], '3916' => ['period' => 'year'],
            '3918' => ['period' => 'year'], '3925' => ['period' => 'year'], '3930' => ['period' => 'year'],
        ],
        'inps' => [
            'DM10' => ['period' => 'month'], 'CXX' => ['period' => 'month'],
        ],
    ];

    public function run(): void
    {
        foreach (self::CODES as $section => $codes) {
            foreach ($codes as $code => $options) {
                F24TaxCode::updateOrCreateGlobal(
                    ['section' => $section, 'code' => (string) $code],
                    [
                        'translation_key' => "treasury.f24.code.{$section}.{$code}",
                        'requires_installment' => false,
                        'period_format' => $options['period'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * Section => codes, for the translation seeder (every code needs a description in the four locales).
     *
     * @return array<string, list<string>>
     */
    public static function codes(): array
    {
        return array_map(static fn (array $codes): array => array_map('strval', array_keys($codes)), self::CODES);
    }
}
