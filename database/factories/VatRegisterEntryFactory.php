<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\VatRegister;
use Modules\GeneralLedger\Models\VatRegisterEntry;
use Modules\GeneralLedger\Models\VatSettlement;

/**
 * @extends Factory<VatRegisterEntry>
 */
final class VatRegisterEntryFactory extends Factory
{
    protected $model = VatRegisterEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'vat_register_id' => static fn (array $attributes): string => (string) VatRegister::create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'code' => 'REG_VEN_1',
                'name' => 'Registro IVA vendite',
                'register_type' => 'sales',
                'progressive_protocol_year' => 2026,
                'last_protocol_number' => 1,
            ])->id,
            'journal_entry_id' => static fn (array $attributes): string => (string) JournalEntryFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
            ])->id,
            'vat_settlement_id' => static fn (array $attributes): string => (string) VatSettlement::create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'period_type' => 'monthly',
                'year' => 2026,
                'period' => 10,
                'country' => 'IT',
                'currency' => 'EUR',
                'period_from' => '2026-10-01',
                'period_to' => '2026-10-31',
                'status' => 'calculated',
            ])->id,
            'protocol_number' => 1,
        ];
    }
}
