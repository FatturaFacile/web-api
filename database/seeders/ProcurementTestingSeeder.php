<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProcurementTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('tenants')->value('id') ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';
        $companyId = Schema::hasTable('companies') ? DB::table('companies')->value('id') : null;

        $supplierId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a99';
        $contact = Contact::firstOrCreate(
            ['id' => $supplierId],
            [
                'tenant_id' => $tenantId,
                'type' => 'supplier',
                'company_name' => 'Test Fixture Supplier S.r.l.',
                'country' => 'IT',
                'email' => 'fixture@supplier.test',
            ]
        );

        $catId = DB::table('supplier_categories')->where('tenant_id', $tenantId)->value('id');

        DB::table('suppliers')->updateOrInsert(
            ['id' => $supplierId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'contact_id' => $contact->id,
                'supplier_category_id' => $catId,
                'supplier_code' => 'SUP-TEST-99',
                'company_name' => 'Test Fixture Supplier S.r.l.',
                'vat_number' => 'IT99988877766',
                'currency' => 'EUR',
                'payment_terms_code' => 'NET30',
                'incoterms' => 'DAP',
                'status' => 'active',
                'is_approved_vendor' => true,
                'credit_limit' => 50000.00,
                'rating' => 'A',
                'is_locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
