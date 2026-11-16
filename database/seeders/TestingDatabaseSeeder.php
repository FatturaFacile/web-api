<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Deterministic multi-tenant seed data for automated regression and end-to-end testing.
 * Provides 2 distinct tenants, role-based users, tax rates, and core records.
 */
final class TestingDatabaseSeeder extends Seeder
{
    public const TENANT_ALPHA_ID = '018f0000-0000-7000-8000-000000000001';

    public const TENANT_BETA_ID = '018f0000-0000-7000-8000-000000000002';

    public function run(): void
    {
        // 1. Seed Tenant Alpha (Swiss/Italian Enterprise)
        $tenantAlpha = Tenant::updateOrCreate(
            ['id' => self::TENANT_ALPHA_ID],
            [
                'name' => 'FatturaFacile Tenant Alpha',
                'subdomain' => 'alpha',
                'vat_number' => 'CHE-123.456.789',
                'country' => 'CH',
                'currency' => 'CHF',
                'is_active' => true,
            ]
        );

        // 2. Seed Tenant Beta (Isolated Italian SME)
        $tenantBeta = Tenant::updateOrCreate(
            ['id' => self::TENANT_BETA_ID],
            [
                'name' => 'FatturaFacile Tenant Beta',
                'subdomain' => 'beta',
                'vat_number' => 'IT12345678901',
                'country' => 'IT',
                'currency' => 'EUR',
                'is_active' => true,
            ]
        );

        // 3. Seed deterministic Users for Tenant Alpha (Admin & Operator)
        $adminAlpha = User::updateOrCreate(
            ['email' => 'admin.alpha@fatturafacile.test'],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantAlpha->id,
                'name' => 'Admin Alpha',
                'password' => Hash::make('Secret123!'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        $operatorAlpha = User::updateOrCreate(
            ['email' => 'operator.alpha@fatturafacile.test'],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantAlpha->id,
                'name' => 'Operator Alpha',
                'password' => Hash::make('Secret123!'),
                'role' => 'operator',
                'email_verified_at' => now(),
            ]
        );

        // 4. Seed deterministic User for Tenant Beta (Isolation verification)
        $adminBeta = User::updateOrCreate(
            ['email' => 'admin.beta@fatturafacile.test'],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantBeta->id,
                'name' => 'Admin Beta',
                'password' => Hash::make('Secret123!'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        // 5. Seed Contacts (Customer & Supplier) for Tenant Alpha
        $customerAlpha = Contact::updateOrCreate(
            ['email' => 'client.lugano@acme.ch'],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantAlpha->id,
                'name' => 'Acme Swiss Client SA',
                'type' => 'customer',
                'tax_number' => 'CHE-987.654.321',
                'country' => 'CH',
                'city' => 'Lugano',
                'address' => 'Via Nassa 10',
                'postal_code' => '6900',
            ]
        );

        $customerBeta = Contact::updateOrCreate(
            ['email' => 'cliente.milano@rossi.it'],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantBeta->id,
                'name' => 'Rossi Forniture S.r.l.',
                'type' => 'customer',
                'tax_number' => 'IT98765432109',
                'country' => 'IT',
                'city' => 'Milano',
                'address' => 'Corso Buenos Aires 45',
                'postal_code' => '20124',
            ]
        );

        // 6. Seed Invoices for Tenant Alpha
        SalesInvoice::updateOrCreate(
            ['invoice_number' => 'INV-2027-ALPHA-001'],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantAlpha->id,
                'contact_id' => $customerAlpha->id,
                'invoice_type' => 'standard',
                'status' => 'posted',
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'CHF',
                'exchange_rate' => 1.0,
                'subtotal' => 1000.00,
                'discount_amount' => 0.00,
                'total_net' => 1000.00,
                'total_tax' => 81.00,
                'total_gross' => 1081.00,
                'receivable_amount' => 1081.00,
                'total_paid' => 0.00,
                'payment_status' => 'unpaid',
                'jurisdiction' => 'CH',
                'payment_method' => 'QRR',
                'payment_terms' => 'TP02',
                'notes' => 'Testing deterministic invoice for Tenant Alpha',
            ]
        );

        // 7. Seed Invoices for Tenant Beta
        SalesInvoice::updateOrCreate(
            ['invoice_number' => 'INV-2027-BETA-001'],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantBeta->id,
                'contact_id' => $customerBeta->id,
                'invoice_type' => 'standard',
                'status' => 'posted',
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'subtotal' => 2000.00,
                'discount_amount' => 0.00,
                'total_net' => 2000.00,
                'total_tax' => 440.00,
                'total_gross' => 2440.00,
                'receivable_amount' => 2440.00,
                'total_paid' => 0.00,
                'payment_status' => 'unpaid',
                'jurisdiction' => 'IT',
                'payment_method' => 'MP05',
                'payment_terms' => 'TP02',
                'notes' => 'Testing deterministic invoice for Tenant Beta (Isolation Check)',
            ]
        );
    }
}
