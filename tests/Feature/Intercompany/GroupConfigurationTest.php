<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\IntercompanyDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\Intercompany\Models\PartyLink;
use Modules\Intercompany\Models\PeriodRate;

require_once __DIR__.'/IntercompanySupport.php';

uses(RefreshDatabase::class);

/**
 * Group settings, party links, the access of the finance roles and the demo seeder.
 */
beforeEach(function (): void {
    icGroupSetUp($this);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a group starts with the default settings and they can be changed', function (): void {
    $this->getJson("/api/v1/intercompany/groups/{$this->group->id}/settings")
        ->assertOk()
        ->assertJsonPath('data.mirror_mode', 'draft')
        ->assertJsonPath('data.auto_mirror', true)
        ->assertJsonPath('data.netting_enabled', true)
        ->assertJsonPath('data.amount_tolerance', '1.0000')
        ->assertJsonPath('data.default_standard', 'fer30');

    $this->putJson("/api/v1/intercompany/groups/{$this->group->id}/settings", [
        'mirror_mode' => 'confirmed', 'auto_mirror' => false, 'sync_payments' => false, 'netting_enabled' => true,
        'amount_tolerance' => 2.5, 'date_tolerance_days' => 10, 'default_standard' => 'oic17',
    ])->assertOk()
        ->assertJsonPath('data.mirror_mode', 'confirmed')
        ->assertJsonPath('data.auto_mirror', false)
        ->assertJsonPath('data.amount_tolerance', '2.5000')
        ->assertJsonPath('data.default_standard', 'oic17');
});

test('invalid settings are refused', function (): void {
    $this->putJson("/api/v1/intercompany/groups/{$this->group->id}/settings", [
        'mirror_mode' => 'automatic', 'auto_mirror' => 'x', 'sync_payments' => true, 'netting_enabled' => true,
        'amount_tolerance' => -1, 'date_tolerance_days' => 500, 'default_standard' => 'gaap',
    ])->assertStatus(422)->assertJsonValidationErrors(['mirror_mode', 'auto_mirror', 'amount_tolerance', 'date_tolerance_days', 'default_standard']);
});

test('party links are listed, unique and removable', function (): void {
    $this->getJson("/api/v1/intercompany/groups/{$this->group->id}/party-links")->assertOk()->assertJsonCount(4, 'data');

    $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/party-links", [
        'entity_tenant_id' => $this->parent->id,
        'counterparty_tenant_id' => $this->italia->id,
        'contact_id' => $this->italiaAsCustomer->id,
        'role' => 'customer',
    ])->assertStatus(422)->assertJsonPath('error_code', 'intercompany.error.party_link_exists');

    $id = PartyLink::withoutGlobalScopes()->where('entity_tenant_id', $this->parent->id)->where('role', 'supplier')->value('id');
    $this->deleteJson("/api/v1/intercompany/party-links/{$id}")->assertOk();
    $this->getJson("/api/v1/intercompany/groups/{$this->group->id}/party-links")->assertOk()->assertJsonCount(3, 'data');
});

test('a read-only accountant reads but cannot change anything', function (): void {
    $reader = icUser($this->parent, 'accountant_readonly');
    icGrant($reader, $this->italia, 'accountant_readonly');
    icActAs($reader, $this->parent);

    $this->getJson("/api/v1/intercompany/groups/{$this->group->id}/settings")->assertOk();
    $this->putJson("/api/v1/intercompany/groups/{$this->group->id}/settings", [
        'mirror_mode' => 'draft', 'auto_mirror' => true, 'sync_payments' => true, 'netting_enabled' => true,
        'amount_tolerance' => 1, 'date_tolerance_days' => 5, 'default_standard' => 'fer30',
    ])->assertForbidden();
    $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/transactions/scan")->assertForbidden();
});

test('a user without a finance role is refused', function (): void {
    $member = icUser($this->parent, 'member');
    icActAs($member, $this->parent);

    $this->getJson('/api/v1/intercompany/lookups')->assertForbidden();
});

test('the demo seeder builds the Swiss and Italian group once, however often it runs', function (): void {
    app(TenantContext::class)->clear();

    $this->seed(IntercompanyDemoSeeder::class);
    $this->seed(IntercompanyDemoSeeder::class);

    $holding = Tenant::withoutGlobalScopes()->where('name', 'Demo Holding AG')->sole();
    $group = ConsolidationGroup::withoutGlobalScopes()->where('tenant_id', $holding->id)->where('name', 'Demo Gruppo CH-IT')->sole();

    expect($group->group_currency)->toBe('CHF')
        ->and(Tenant::withoutGlobalScopes()->where('name', 'Demo Italia Srl')->count())->toBe(1)
        ->and(PartyLink::withoutGlobalScopes()->where('group_id', $group->id)->count())->toBe(2)
        ->and(PeriodRate::withoutGlobalScopes()->where('tenant_id', $holding->id)->count())->toBe(3);
});
