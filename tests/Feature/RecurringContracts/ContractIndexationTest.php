<?php

declare(strict_types=1);

namespace Tests\Feature\RecurringContracts;

use App\Models\Contact;
use App\Models\RecurringContracts\ContractIndexationClause;
use App\Models\RecurringContracts\PriceIndexRate;
use App\Models\RecurringContracts\RecurringContract;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RecurringContracts\IndexationAdjustmentEngine;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractIndexationTest extends TestCase
{
    protected bool $seed = false;

    private Tenant $tenant;

    private User $user;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Indexation Test Tenant',
            'legal_name' => 'Indexation Test Tenant SRL',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]);

        $this->user = User::create([
            'name' => 'Accountant User',
            'email' => 'index_user_'.Str::random(6).'@example.com',
            'password' => bcrypt('secret123'),
            'locale' => 'en',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

        Sanctum::actingAs($this->user);
        $this->withHeader('X-Tenant-Id', $this->tenant->id);

        $this->customer = Contact::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'customer',
            'company_name' => 'Locatario Milano S.p.A.',
            'country' => 'IT',
        ]);
    }

    public function test_istat_foi_indexation_with_75_percent_pass_through(): void
    {
        $contract = RecurringContract::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'contract_number' => 'CTR-ISTAT-001',
            'title' => 'Office Lease Rental Milan',
            'contract_type' => 'rental',
            'status' => 'active',
            'start_date' => '2025-01-01',
            'billing_frequency' => 'monthly',
            'billing_timing' => 'in_advance',
            'next_billing_date' => '2026-01-01',
            'currency' => 'EUR',
        ]);

        // Base rent line: 1000 EUR
        $contract->lines()->create([
            'tenant_id' => $this->tenant->id,
            'description' => 'Canone Locazione Mensile',
            'quantity' => 1,
            'unit_price' => '1000.0000',
            'tax_rate' => '0.00',
            'proration_mode' => 'none',
        ]);

        // Clause: Base index 115.0000 (2024-12), 75% pass-through
        $clause = ContractIndexationClause::create([
            'tenant_id' => $this->tenant->id,
            'recurring_contract_id' => $contract->id,
            'index_type' => 'istat_foi',
            'base_index_value' => '115.0000',
            'base_index_period' => '2024-12',
            'adjustment_frequency_months' => 12,
            'pass_through_percent' => '75.00',
            'next_adjustment_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // Target ISTAT FOI rate: 119.6000 (2025-12)
        // Delta = ((119.6 - 115.0) / 115.0) * 100 = 4.0000%
        // Applied with 75% pass-through = 4.0000 * 0.75 = 3.0000%
        // New price = 1000 * (1 + 0.03) = 1030.0000 EUR
        $targetRate = PriceIndexRate::create([
            'tenant_id' => $this->tenant->id,
            'index_type' => 'istat_foi',
            'period' => '2025-12',
            'index_value' => '119.6000',
            'published_at' => '2026-01-15',
            'source_reference' => 'ISTAT Comunicato Ufficiale',
        ]);

        $engine = app(IndexationAdjustmentEngine::class);
        $log = $engine->applyIndexation($contract, $targetRate, $this->user->id);

        $this->assertEquals('4.0000', $log->raw_delta_percent);
        $this->assertEquals('3.0000', $log->applied_delta_percent);
        $this->assertEquals('1000.0000', $log->old_recurring_total);
        $this->assertEquals('1030.0000', $log->new_recurring_total);

        $contract->refresh();
        $this->assertEquals('1030.0000', $contract->lines()->first()->unit_price);
        $clause->refresh();
        $this->assertEquals('119.6000', $clause->base_index_value);
        $this->assertEquals('2025-12', $clause->base_index_period);
        $this->assertEquals('2027-01-01', $clause->next_adjustment_date->toDateString());
    }

    public function test_swiss_ipc_indexation_with_floor_and_cap_clamping(): void
    {
        $contract = RecurringContract::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'contract_number' => 'CTR-CH-IPC-001',
            'title' => 'Software Support Zurich',
            'contract_type' => 'software_license',
            'status' => 'active',
            'start_date' => '2025-01-01',
            'billing_frequency' => 'quarterly',
            'billing_timing' => 'in_advance',
            'next_billing_date' => '2026-01-01',
            'currency' => 'CHF',
        ]);

        $contract->lines()->create([
            'tenant_id' => $this->tenant->id,
            'description' => 'Enterprise License CHF',
            'quantity' => 1,
            'unit_price' => '5000.0000',
            'tax_rate' => '8.10',
            'proration_mode' => 'none',
        ]);

        // Clause: Base index 100.0000, 100% pass-through, floor 1.00%, cap 3.00%
        ContractIndexationClause::create([
            'tenant_id' => $this->tenant->id,
            'recurring_contract_id' => $contract->id,
            'index_type' => 'swiss_ipc',
            'base_index_value' => '100.0000',
            'base_index_period' => '2024-12',
            'adjustment_frequency_months' => 12,
            'pass_through_percent' => '100.00',
            'floor_percent' => '1.00',
            'cap_percent' => '3.00',
            'next_adjustment_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // Target index jumps by 10% (from 100 to 110)
        $targetRate = PriceIndexRate::create([
            'tenant_id' => $this->tenant->id,
            'index_type' => 'swiss_ipc',
            'period' => '2025-12',
            'index_value' => '110.0000',
            'published_at' => '2026-01-10',
            'source_reference' => 'OFS Indice dei prezzi al consumo',
        ]);

        $response = $this->postJson("/api/v1/recurring-contracts/{$contract->id}/apply-indexation", [
            'price_index_rate_id' => $targetRate->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Raw was 10.0000%, but capped at 3.0000%
        $this->assertEquals('10.0000', $data['raw_delta_percent']);
        $this->assertEquals('3.0000', $data['applied_delta_percent']);

        $contract->refresh();
        // 5000 * 1.03 = 5150.0000
        $this->assertEquals('5150.0000', $contract->lines()->first()->unit_price);
    }
}
