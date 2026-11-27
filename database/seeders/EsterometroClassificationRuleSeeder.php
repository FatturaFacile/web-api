<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Esterometro\Models\SelfInvoiceClassificationRule;

final class EsterometroClassificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'code' => 'TD16_DOMESTIC_RC_N6',
                'name' => 'Reverse charge interno (N6.1–N6.9)',
                'document_type' => 'TD16',
                'supplier_region' => 'IT',
                'supplier_country_code' => 'IT',
                'transaction_nature' => 'DOMESTIC_RC',
                'source_vat_nature_prefix' => 'N6',
                'default_vat_rate' => 22.00,
                'default_vat_nature' => null,
                'default_deductibility_percent' => 100.00,
                'soggetto_emittente' => 'CC',
                'priority' => 10,
                'legal_reference' => 'Art. 17 c.5-6 DPR 633/72',
            ],
            [
                'code' => 'TD17_EU_SERVICES',
                'name' => 'Servizi da fornitore UE (art. 7-ter)',
                'document_type' => 'TD17',
                'supplier_region' => 'EU',
                'supplier_country_code' => null,
                'transaction_nature' => 'SERVICES',
                'source_vat_nature_prefix' => null,
                'default_vat_rate' => 22.00,
                'default_vat_nature' => null,
                'default_deductibility_percent' => 100.00,
                'soggetto_emittente' => 'CC',
                'priority' => 20,
                'legal_reference' => 'Art. 17 c.2 / Art. 7-ter DPR 633/72',
            ],
            [
                'code' => 'TD17_EXTRA_EU_SERVICES',
                'name' => 'Servizi da fornitore Extra-UE (autofattura)',
                'document_type' => 'TD17',
                'supplier_region' => 'EXTRA_EU',
                'supplier_country_code' => null,
                'transaction_nature' => 'SERVICES',
                'source_vat_nature_prefix' => null,
                'default_vat_rate' => 22.00,
                'default_vat_nature' => null,
                'default_deductibility_percent' => 100.00,
                'soggetto_emittente' => 'CC',
                'priority' => 30,
                'legal_reference' => 'Art. 17 c.2 DPR 633/72',
            ],
            [
                'code' => 'TD18_EU_GOODS',
                'name' => 'Acquisto intracomunitario di beni',
                'document_type' => 'TD18',
                'supplier_region' => 'EU',
                'supplier_country_code' => null,
                'transaction_nature' => 'GOODS',
                'source_vat_nature_prefix' => null,
                'default_vat_rate' => 22.00,
                'default_vat_nature' => null,
                'default_deductibility_percent' => 100.00,
                'soggetto_emittente' => 'CC',
                'priority' => 40,
                'legal_reference' => 'Art. 38 DL 331/93',
            ],
            [
                'code' => 'TD19_GOODS_IN_ITALY',
                'name' => 'Beni già presenti in Italia da fornitore estero',
                'document_type' => 'TD19',
                'supplier_region' => 'ANY',
                'supplier_country_code' => null,
                'transaction_nature' => 'GOODS_IN_ITALY',
                'source_vat_nature_prefix' => null,
                'default_vat_rate' => 22.00,
                'default_vat_nature' => null,
                'default_deductibility_percent' => 100.00,
                'soggetto_emittente' => 'CC',
                'priority' => 50,
                'legal_reference' => 'Art. 17 c.2 DPR 633/72',
            ],
            [
                'code' => 'TD20_REGULARIZATION',
                'name' => 'Autofattura regolarizzazione (omessa/irregolare)',
                'document_type' => 'TD20',
                'supplier_region' => 'ANY',
                'supplier_country_code' => null,
                'transaction_nature' => 'REGULARIZATION',
                'source_vat_nature_prefix' => null,
                'default_vat_rate' => 22.00,
                'default_vat_nature' => null,
                'default_deductibility_percent' => 100.00,
                'soggetto_emittente' => 'CC',
                'priority' => 60,
                'legal_reference' => 'Art. 6 c.8 e 9-bis D.Lgs. 471/97',
            ],
            [
                'code' => 'TD28_SAN_MARINO',
                'name' => 'Acquisti da San Marino',
                'document_type' => 'TD28',
                'supplier_region' => 'SM',
                'supplier_country_code' => 'SM',
                'transaction_nature' => 'SAN_MARINO',
                'source_vat_nature_prefix' => null,
                'default_vat_rate' => 22.00,
                'default_vat_nature' => null,
                'default_deductibility_percent' => 100.00,
                'soggetto_emittente' => 'CC',
                'priority' => 70,
                'legal_reference' => 'DM 21/06/2021',
            ],
        ];

        foreach ($rules as $rule) {
            SelfInvoiceClassificationRule::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $rule['code']],
                $rule + ['is_active' => true],
            );
        }
    }
}
