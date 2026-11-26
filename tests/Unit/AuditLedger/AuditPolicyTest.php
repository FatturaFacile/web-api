<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Policies\AuditPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = AuditLedgerFixtures::tenant('Policy Tenant');
    app(TenantContext::class)->setTenant($this->tenant);
});

test('search, export and verify are open to owner, admin, external auditor and the dpo', function (string $role): void {
    $user = $role === 'owner' ? $this->owner : AuditLedgerFixtures::member($this->tenant, $role);
    $policy = app(AuditPolicy::class);

    expect($policy->search($user))->toBeTrue()
        ->and($policy->export($user))->toBeTrue()
        ->and($policy->verify($user))->toBeTrue();
})->with(['owner', 'admin', 'external_auditor', 'dpo']);

test('every other role is refused', function (string $role): void {
    $user = AuditLedgerFixtures::member($this->tenant, $role);
    $policy = app(AuditPolicy::class);

    expect($policy->search($user))->toBeFalse()
        ->and($policy->export($user))->toBeFalse()
        ->and($policy->verify($user))->toBeFalse();
})->with(['member', 'accountant', 'sales', 'technician', 'purchasing']);

test('a member granted one audit permission gets only that ability', function (): void {
    $user = AuditLedgerFixtures::member($this->tenant, 'member');
    DB::table('tenant_user')->where('user_id', $user->id)->update(['permissions' => json_encode(['audit.view'])]);
    $policy = app(AuditPolicy::class);

    expect($policy->search($user))->toBeTrue()
        ->and($policy->export($user))->toBeFalse()
        ->and($policy->verify($user))->toBeFalse();
});

test('a role of another tenant gives no access', function (): void {
    ['tenant' => $other, 'user' => $foreignOwner] = AuditLedgerFixtures::tenant('Other Tenant');

    expect(app(AuditPolicy::class)->search($foreignOwner))->toBeFalse();
});

test('the abilities are registered on the gate', function (): void {
    expect(Gate::forUser($this->owner)->allows('audit-ledger.search'))->toBeTrue()
        ->and(Gate::forUser(AuditLedgerFixtures::member($this->tenant, 'member'))->allows('audit-ledger.export'))->toBeFalse();
});

test('the history of a record follows the right to read its module', function (): void {
    $member = AuditLedgerFixtures::member($this->tenant, 'member');
    $technician = AuditLedgerFixtures::member($this->tenant, 'technician');
    $policy = app(AuditPolicy::class);

    // Every member reads invoicing and the CRM; only owners and administrators read the user accounts.
    expect($policy->history($member, 'Invoice'))->toBeTrue()
        ->and($policy->history($member, 'Contact'))->toBeTrue()
        ->and($policy->history($member, 'User'))->toBeFalse()
        ->and($policy->history($this->owner, 'User'))->toBeTrue()
        ->and($policy->history($technician, 'Payment'))->toBeTrue();
});

test('a resource type the ledger does not expose is refused to everybody', function (): void {
    expect(app(AuditPolicy::class)->history($this->owner, 'Secret'))->toBeFalse()
        ->and(app(AuditPolicy::class)->history($this->owner, 'App\\Models\\Unknown'))->toBeFalse();
});

test('the fully qualified class of an exposed resource is accepted', function (): void {
    expect(app(AuditPolicy::class)->history($this->owner, 'App\\Models\\Invoice'))->toBeTrue();
});
