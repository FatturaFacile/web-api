<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Country;
use App\Models\PasswordHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Exceptions\MissingTenantContextException;
use Enterprise\MultiTenant\Queue\TenantQueueContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

/**
 * Owner policy: every table has a nullable tenant_id; null means global (visible to every tenant) and is allowed only
 * for the models that declare it; a tenant record can never be saved with a null tenant by accident.
 *
 * The schema side (every non excluded table has a nullable tenant_id) is checked by TenantModelCoverageTest.
 */
beforeEach(function (): void {
    $this->tenantA = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->tenantB = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'CH', 'default_currency' => 'CHF']);
});

function policyCountry(string $code, bool $global = true): Country
{
    $attributes = ['iso_code_2' => $code, 'iso_code_3' => $code.'X', 'numeric_code' => 'N'.$code, 'phone_prefix' => '+999', 'currency_code' => 'CHF'];

    return $global ? Country::createGlobal($attributes) : Country::query()->create($attributes);
}

function policyPlatformAdmin(): User
{
    $user = User::create([
        'name' => 'Platform '.Str::random(4),
        'email' => Str::random(12).'@example.test',
        'password' => bcrypt(Str::password(24)),
        'locale' => 'it',
    ]);
    $user->forceFill(['is_platform_admin' => true])->save();

    return $user;
}

test('a tenant record cannot be created without a tenant context', function (): void {
    app(TenantContext::class)->clear();

    expect(fn () => Contact::create(['company_name' => 'No tenant', 'country' => 'CH']))->toThrow(MissingTenantContextException::class);
    expect(DB::table('contacts')->where('company_name', 'No tenant')->exists())->toBeFalse();
});

test('a tenant record takes the tenant of the context, or the tenant_id given by console code', function (): void {
    $context = app(TenantContext::class);
    $context->runWithTenant($this->tenantA, function (): void {
        $contact = Contact::create(['company_name' => 'From context', 'country' => 'CH']);
        expect($contact->tenant_id)->toBe($this->tenantA->id);
    });

    $byConsole = Contact::create(['tenant_id' => $this->tenantB->id, 'company_name' => 'By console', 'country' => 'CH']);
    expect($byConsole->tenant_id)->toBe($this->tenantB->id);
});

test('a null tenant_id passed explicitly is not a declaration of global', function (): void {
    app(TenantContext::class)->clear();

    expect(fn () => Contact::create(['tenant_id' => null, 'company_name' => 'Null by hand', 'country' => 'CH']))->toThrow(MissingTenantContextException::class);
});

test('the explicit global path creates a record visible to every tenant, only for a model that allows it', function (): void {
    $context = app(TenantContext::class);
    $country = policyCountry('ZA');

    expect(DB::table('countries')->where('id', $country->id)->value('tenant_id'))->toBeNull();

    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        expect($context->runWithTenant($tenant, fn (): bool => Country::query()->whereKey($country->id)->exists()))->toBeTrue();
    }

    $context->clear();
    expect(Country::query()->whereKey($country->id)->exists())->toBeTrue();

    expect(fn () => Contact::createGlobal(['company_name' => 'Not shared', 'country' => 'CH']))->toThrow(MissingTenantContextException::class, 'does not allow global records');
    expect(fn () => $context->runAsGlobal(fn () => Contact::create(['company_name' => 'Not shared', 'country' => 'CH'])))->toThrow(MissingTenantContextException::class);
    expect(fn () => Contact::makeGlobal(['company_name' => 'Not shared', 'country' => 'CH'])->save())->toThrow(MissingTenantContextException::class);
});

test('makeGlobal, updateOrCreateGlobal and firstOrCreateGlobal store a null tenant', function (): void {
    $made = Country::makeGlobal(['iso_code_2' => 'ZB', 'iso_code_3' => 'ZBX', 'numeric_code' => 'NZB', 'phone_prefix' => '+998', 'currency_code' => 'CHF']);
    $made->save();

    $updated = Country::updateOrCreateGlobal(['iso_code_2' => 'ZC'], ['iso_code_3' => 'ZCX', 'numeric_code' => 'NZC', 'phone_prefix' => '+997', 'currency_code' => 'CHF']);
    $found = Country::firstOrCreateGlobal(['iso_code_2' => 'ZC'], ['iso_code_3' => 'ZZZ', 'numeric_code' => 'N00', 'phone_prefix' => '+0', 'currency_code' => 'EUR']);

    expect(DB::table('countries')->whereIn('iso_code_2', ['ZB', 'ZC'])->whereNull('tenant_id')->count())->toBe(2)
        ->and($found->id)->toBe($updated->id);
});

test('a tenant cannot create a global record, a platform administrator can', function (): void {
    $context = app(TenantContext::class);

    $context->runWithTenant($this->tenantA, function (): void {
        expect(fn () => policyCountry('ZD'))->toThrow(AccessDeniedHttpException::class);
    });

    $admin = policyPlatformAdmin();
    $this->actingAs($admin);
    $context->runWithTenant($this->tenantA, function (): void {
        $country = policyCountry('ZE');
        expect($country->tenant_id)->toBeNull();
    });
});

test('tenant B cannot update or delete a global row, nor move a record to another tenant', function (): void {
    $context = app(TenantContext::class);
    $country = policyCountry('ZF');

    $context->runWithTenant($this->tenantB, function () use ($country): void {
        $visible = Country::query()->whereKey($country->id)->firstOrFail();

        expect(fn () => $visible->update(['currency_code' => 'EUR']))->toThrow(AccessDeniedHttpException::class)
            ->and(fn () => $visible->delete())->toThrow(AccessDeniedHttpException::class);
    });

    expect(DB::table('countries')->where('id', $country->id)->value('currency_code'))->toBe('CHF');

    $contact = $context->runWithTenant($this->tenantA, fn (): Contact => Contact::create(['company_name' => 'Mine', 'country' => 'CH']));

    $context->runWithTenant($this->tenantA, function () use ($contact): void {
        $mine = Contact::query()->whereKey($contact->id)->firstOrFail();
        $mine->tenant_id = $this->tenantB->id;

        expect(fn () => $mine->save())->toThrow(AccessDeniedHttpException::class);
    });
});

test('a platform administrator may change a global row from a tenant context', function (): void {
    $country = policyCountry('ZG');
    $this->actingAs(policyPlatformAdmin());

    app(TenantContext::class)->runWithTenant($this->tenantA, function () use ($country): void {
        expect(Country::query()->whereKey($country->id)->firstOrFail()->update(['currency_code' => 'EUR']))->toBeTrue();
    });

    expect(DB::table('countries')->where('id', $country->id)->value('currency_code'))->toBe('EUR');
});

test('a model that does not allow global records never exposes a null tenant row', function (): void {
    DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'company_name' => 'Legacy orphan', 'country' => 'CH', 'created_at' => now(), 'updated_at' => now()]);
    $context = app(TenantContext::class);

    expect($context->runWithTenant($this->tenantA, fn (): bool => Contact::query()->where('company_name', 'Legacy orphan')->exists()))->toBeFalse()
        ->and($context->runWithTenant($this->tenantB, fn (): bool => Contact::query()->where('company_name', 'Legacy orphan')->exists()))->toBeFalse();

    $context->clear();
    expect(Contact::query()->where('company_name', 'Legacy orphan')->exists())->toBeFalse();
});

test('a tenant keeps its own row next to a global one of a model that allows both', function (): void {
    $global = policyCountry('ZH');
    $own = app(TenantContext::class)->runWithTenant($this->tenantA, fn (): Country => policyCountry('ZI', global: false));

    $seenByA = app(TenantContext::class)->runWithTenant($this->tenantA, fn (): array => Country::query()->whereIn('iso_code_2', ['ZH', 'ZI'])->pluck('id')->all());
    $seenByB = app(TenantContext::class)->runWithTenant($this->tenantB, fn (): array => Country::query()->whereIn('iso_code_2', ['ZH', 'ZI'])->pluck('id')->all());

    expect($seenByA)->toContain($global->id, $own->id)
        ->and($seenByB)->toBe([$global->id]);
});

test('a child row takes the tenant of its parent and refuses a parent of another tenant', function (): void {
    $context = app(TenantContext::class);
    $contact = $context->runWithTenant($this->tenantA, fn (): Contact => Contact::create(['company_name' => 'Parent', 'country' => 'CH']));
    $address = ['contact_id' => $contact->id, 'street' => 'Via Roma', 'postal_code' => '6900', 'city' => 'Lugano'];

    $byConsole = ContactAddress::create($address);
    expect($byConsole->tenant_id)->toBe($this->tenantA->id);

    $fromContext = $context->runWithTenant($this->tenantA, fn (): ContactAddress => ContactAddress::create($address));
    expect($fromContext->tenant_id)->toBe($this->tenantA->id);

    expect(fn () => $context->runWithTenant($this->tenantB, fn (): ContactAddress => ContactAddress::create($address)))->toThrow(AccessDeniedHttpException::class);

    $context->clear();
    expect(fn () => ContactAddress::create(['contact_id' => (string) Str::uuid()] + $address))->toThrow(MissingTenantContextException::class);
});

test('PasswordHistory rows are global user data written through the explicit global path', function (): void {
    $user = User::create([
        'name' => 'History',
        'email' => Str::random(12).'@example.test',
        'password' => bcrypt(Str::password(24)),
        'locale' => 'it',
    ]);

    app(TenantContext::class)->clear();
    $row = PasswordHistory::createGlobal(['user_id' => $user->id, 'password_hash' => bcrypt(Str::password(24))]);

    expect(DB::table('password_histories')->where('id', $row->id)->value('tenant_id'))->toBeNull()
        ->and(fn () => PasswordHistory::create(['user_id' => $user->id, 'password_hash' => 'x']))->toThrow(MissingTenantContextException::class);
});

test('a queued job keeps the tenant context of the dispatcher and the worker has none without it', function (): void {
    $context = app(TenantContext::class);
    $queueContext = app(TenantQueueContext::class);

    $payload = $context->runWithTenant($this->tenantA, fn (): array => $queueContext->payload());
    $emptyPayload = $queueContext->payload();
    $context->clear();

    expect($payload['tenant_id'])->toBe($this->tenantA->id)
        ->and($emptyPayload['tenant_id'])->toBeNull();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);

    event(new JobProcessing('sync', $job));
    $contact = Contact::create(['company_name' => 'Created by the job', 'country' => 'CH']);
    event(new JobProcessed('sync', $job));

    expect($contact->tenant_id)->toBe($this->tenantA->id)
        ->and($context->isExplicit())->toBeFalse()
        ->and(fn () => Contact::create(['company_name' => 'After the job', 'country' => 'CH']))->toThrow(MissingTenantContextException::class);

    $emptyJob = Mockery::mock(Job::class);
    $emptyJob->shouldReceive('payload')->andReturn($emptyPayload);

    event(new JobProcessing('sync', $emptyJob));
    expect(fn () => Contact::create(['company_name' => 'Job with no tenant', 'country' => 'CH']))->toThrow(MissingTenantContextException::class);
    event(new JobProcessed('sync', $emptyJob));
});

test('the legacy backfill command assigns orphaned rows of tenant models and leaves the global rows alone', function (): void {
    $global = policyCountry('ZJ');
    $orphanId = (string) Str::uuid();
    DB::table('contacts')->insert(['id' => $orphanId, 'tenant_id' => null, 'company_name' => 'Legacy', 'country' => 'CH', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('tenancy:backfill', ['tenant_id' => $this->tenantA->id, '--model' => Country::class])->assertSuccessful();
    $this->artisan('tenancy:backfill', ['tenant_id' => $this->tenantA->id, '--model' => Contact::class])->assertSuccessful();

    expect(DB::table('countries')->where('id', $global->id)->value('tenant_id'))->toBeNull()
        ->and(DB::table('contacts')->where('id', $orphanId)->value('tenant_id'))->toBe($this->tenantA->id);
});
