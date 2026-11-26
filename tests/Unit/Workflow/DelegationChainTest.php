<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Workflow\Actions\CreateDelegationAction;
use Modules\Workflow\DTOs\CreateDelegationDTO;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Services\WorkflowDelegationResolver;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['id' => (string) Str::uuid7(), 'name' => 'Chain AG', 'legal_name' => 'Chain AG', 'status' => 'active']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->users = collect(['a', 'b', 'c', 'd', 'e'])->mapWithKeys(function (string $key): array {
        $user = User::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'name' => strtoupper($key), 'email' => $key.'_'.Str::random(6).'@chain.test', 'password' => bcrypt('secret')]);
        $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

        return [$key => $user];
    });

    $this->resolver = app(WorkflowDelegationResolver::class);

    $this->delegate = function (string $from, string $to, array $extra = []): WorkflowDelegation {
        return app(CreateDelegationAction::class)->execute(new CreateDelegationDTO(
            delegatorUserId: (string) $this->users[$from]->id,
            delegateUserId: (string) $this->users[$to]->id,
            startsAt: now()->subHour(),
            endsAt: now()->addDays(3),
            scope: $extra['scope'] ?? 'all',
            tenantId: (string) $this->tenant->id,
            documentTypes: $extra['document_types'] ?? null,
            amountCap: $extra['amount_cap'] ?? null,
        ));
    };

    $this->standIn = fn (string $from, string $type = 'purchase_order', ?float $amount = null): string => array_search(
        $this->resolver->resolve((string) $this->users[$from]->id, (string) $this->tenant->id, $type, $amount),
        $this->users->map(fn (User $user): string => (string) $user->id)->all(),
        true
    );
});

test('a chain A to B to C is followed to its last delegate', function (): void {
    ($this->delegate)('a', 'b');
    ($this->delegate)('b', 'c');

    expect(($this->standIn)('a'))->toBe('c')
        ->and(($this->standIn)('b'))->toBe('c')
        ->and(($this->standIn)('c'))->toBe('c');
});

test('a chain is followed three hops at most', function (): void {
    ($this->delegate)('a', 'b');
    ($this->delegate)('b', 'c');
    ($this->delegate)('c', 'd');
    ($this->delegate)('d', 'e');

    expect(($this->standIn)('a'))->toBe('d');
});

test('a delegation that would close a loop is refused on save', function (): void {
    ($this->delegate)('a', 'b');
    ($this->delegate)('b', 'c');

    expect(fn () => ($this->delegate)('c', 'a'))->toThrow(ValidationException::class);

    try {
        ($this->delegate)('c', 'a');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('delegate_user_id');
    }

    expect(WorkflowDelegation::query()->count())->toBe(2);
});

test('a delegation to oneself is refused', function (): void {
    expect(fn () => ($this->delegate)('a', 'a'))->toThrow(ValidationException::class);
});

test('the chain stops where a delegation does not cover the document type', function (): void {
    ($this->delegate)('a', 'b');
    ($this->delegate)('b', 'c', ['document_types' => ['expense_report']]);

    expect(($this->standIn)('a', 'purchase_order'))->toBe('b')
        ->and(($this->standIn)('a', 'expense_report'))->toBe('c');
});

test('the chain stops where an amount cap is exceeded', function (): void {
    ($this->delegate)('a', 'b', ['amount_cap' => '1000']);
    ($this->delegate)('b', 'c', ['amount_cap' => '500']);

    expect(($this->standIn)('a', 'purchase_order', 400.0))->toBe('c')
        ->and(($this->standIn)('a', 'purchase_order', 800.0))->toBe('b')
        ->and(($this->standIn)('a', 'purchase_order', 1500.0))->toBe('a');
});

test('an expired or inactive delegation is not part of the chain', function (): void {
    $past = ($this->delegate)('a', 'b');
    $past->update(['ends_at' => now()->subMinute()]);
    $inactive = ($this->delegate)('b', 'c');
    $inactive->update(['is_active' => false]);

    expect(($this->standIn)('a'))->toBe('a')
        ->and(($this->standIn)('b'))->toBe('b');
});

test('delegations of another tenant never take part in the chain', function (): void {
    $other = Tenant::create(['id' => (string) Str::uuid7(), 'name' => 'Other AG', 'legal_name' => 'Other AG', 'status' => 'active']);
    WorkflowDelegation::withoutGlobalScopes()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $other->id,
        'delegator_user_id' => $this->users['a']->id,
        'delegate_user_id' => $this->users['b']->id,
        'scope' => 'all',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    expect(($this->standIn)('a'))->toBe('a');
});
