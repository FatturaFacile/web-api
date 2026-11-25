<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\Intercompany\Models\GroupSettings;
use Modules\Intercompany\Models\PartyLink;
use Modules\Intercompany\Models\PeriodRate;

/**
 * Demo group for the intercompany module: a Swiss holding (CHF) that owns 80 % of an Italian subsidiary (EUR), with the
 * contacts that stand for each entity in the books of the other, the mirror and netting settings and the closing, average
 * and historical CHF/EUR rates of one period. Idempotent; run on demand (`db:seed --class=IntercompanyDemoSeeder`), it is not
 * part of the testing seeder.
 */
final class IntercompanyDemoSeeder extends Seeder
{
    public function run(): void
    {
        $holding = $this->tenant('Demo Holding AG', 'Demo Holding AG', 'CH', 'CHF');
        $italia = $this->tenant('Demo Italia Srl', 'Demo Italia Srl', 'IT', 'EUR');

        $contexts = app(TenantContext::class);

        // Contacts that stand for each entity in the books of the other one.
        $italiaAsCustomer = $contexts->runWithTenant($holding, fn (): Contact => $this->contact($holding, $italia->name, 'client'));
        $holdingAsSupplier = $contexts->runWithTenant($italia, fn (): Contact => $this->contact($italia, $holding->name, 'supplier'));

        $group = ConsolidationGroup::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $holding->id, 'name' => 'Demo Gruppo CH-IT'],
            ['group_currency' => 'CHF', 'is_active' => true],
        );

        foreach ([[$holding, '100', true], [$italia, '80', false]] as [$entity, $ownership, $isParent]) {
            ConsolidationMember::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $holding->id, 'group_id' => $group->id, 'member_tenant_id' => $entity->id],
                ['ownership_percentage' => $ownership, 'consolidation_method' => 'full', 'is_parent' => $isParent],
            );
        }

        $contexts->runWithTenant($holding, function () use ($holding, $italia, $group, $italiaAsCustomer, $holdingAsSupplier): void {
            GroupSettings::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $holding->id, 'group_id' => $group->id],
                ['mirror_mode' => 'draft', 'auto_mirror' => true, 'sync_payments' => true, 'netting_enabled' => true, 'amount_tolerance' => '1.0000', 'date_tolerance_days' => 5, 'default_standard' => 'fer30'],
            );

            PartyLink::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $holding->id, 'group_id' => $group->id, 'entity_tenant_id' => $holding->id, 'counterparty_tenant_id' => $italia->id, 'role' => 'customer'],
                ['contact_id' => $italiaAsCustomer->id],
            );
            PartyLink::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $holding->id, 'group_id' => $group->id, 'entity_tenant_id' => $italia->id, 'counterparty_tenant_id' => $holding->id, 'role' => 'supplier'],
                ['contact_id' => $holdingAsSupplier->id],
            );

            foreach (['closing' => '1.04000000', 'average' => '1.02500000', 'historical' => '1.00000000'] as $type => $rate) {
                PeriodRate::withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $holding->id, 'group_currency' => 'CHF', 'currency' => 'EUR', 'rate_type' => $type, 'period_end' => '2027-12-31'],
                    ['group_id' => $group->id, 'rate' => $rate, 'note' => 'Demo rate'],
                );
            }
        });
    }

    private function tenant(string $name, string $legalName, string $country, string $currency): Tenant
    {
        return Tenant::withoutGlobalScopes()->firstOrCreate(['name' => $name], ['legal_name' => $legalName, 'country' => $country, 'default_currency' => $currency]);
    }

    private function contact(Tenant $owner, string $companyName, string $type): Contact
    {
        return Contact::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $owner->id, 'company_name' => $companyName],
            ['type' => $type, 'country' => $owner->country === 'CH' ? 'IT' : 'CH'],
        );
    }
}
