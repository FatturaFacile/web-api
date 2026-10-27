<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Tenant::updateOrCreate(
            ['vat_number' => 'IT12345678901'],
            [
                'name' => 'Fattura Facile Italia S.r.l.',
                'legal_name' => 'Fattura Facile Italia S.r.l.',
                'fiscal_code' => '12345678901',
                'country' => 'IT',
                'default_currency' => 'EUR',
                'sdi_code' => 'M5UXCR1',
                'pec_email' => 'fatturafacile@pec.it',
                'iban' => 'IT60X0542811101000000123456',
            ]
        );

        Tenant::updateOrCreate(
            ['uid_number' => 'CHE-123.456.789'],
            [
                'name' => 'Fattura Facile Schweiz AG',
                'legal_name' => 'Fattura Facile Schweiz AG',
                'country' => 'CH',
                'default_currency' => 'CHF',
                'iban' => 'CH9300762011623852957',
                'qr_iban' => 'CH4431999123000889012',
            ]
        );
    }
}
