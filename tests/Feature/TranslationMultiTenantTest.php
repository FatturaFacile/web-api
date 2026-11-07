<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Cache::flush();

    $this->tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Acme Corporation',
        'legal_name' => 'Acme Corporation S.r.l.',
        'vat_number' => 'IT12345678901',
        'status' => 'active',
    ]);
});

test('GET translations with tenant_id=default returns 200 with tenant_id null and valid dictionary', function (): void {
    Translation::create([
        'tenant_id' => null,
        'locale' => 'en',
        'key' => 'AUTH.SUBTITLE',
        'value' => 'Sign in to your account',
    ]);

    Translation::create([
        'tenant_id' => null,
        'locale' => 'en',
        'key' => 'AUTH.EMAIL',
        'value' => 'Email Address',
    ]);

    $response = $this->getJson('/api/v1/translations/en?tenant_id=default');

    $response->assertOk()
        ->assertJson([
            'locale' => 'en',
            'tenant_id' => null,
        ]);

    $data = $response->json();

    expect($data['tenant_id'])->toBeNull()
        ->and($data['translations'])->toBeArray()
        ->and($data['translations'])->toHaveKeys(['AUTH.SUBTITLE', 'AUTH.EMAIL'])
        ->and($data['translations']['AUTH.SUBTITLE'])->toBe('Sign in to your account')
        ->and($data['translations']['AUTH.EMAIL'])->toBe('Email Address')
        ->and(array_key_exists('__PHP_Incomplete_Class_Name', $data['translations']))->toBeFalse();
});

test('translations endpoint does not output __PHP_Incomplete_Class_Name even if collection was previously in cache', function (): void {
    Translation::create([
        'tenant_id' => null,
        'locale' => 'en',
        'key' => 'COMMON.WELCOME',
        'value' => 'Welcome to the platform',
    ]);

    // Intentionally populate cache with an Illuminate Collection as previously bugged
    $collection = collect(['COMMON.WELCOME' => 'Welcome to the platform']);
    Cache::forever('translations_global_en', $collection);

    $response = $this->getJson('/api/v1/translations/en?tenant_id=default');

    $response->assertOk();
    $data = $response->json();

    expect($data['translations'])->toBeArray()
        ->and(array_key_exists('__PHP_Incomplete_Class_Name', $data['translations']))->toBeFalse()
        ->and($data['translations']['COMMON.WELCOME'])->toBe('Welcome to the platform');
});

test('query with specific tenant ID uses null tenant as fallback and tenant specific values take precedence', function (): void {
    // 1. Global default translation
    Translation::create([
        'tenant_id' => null,
        'locale' => 'en',
        'key' => 'AUTH.LOGIN',
        'value' => 'System Login',
    ]);

    // 2. Global fallback translation (not overridden by tenant)
    Translation::create([
        'tenant_id' => null,
        'locale' => 'en',
        'key' => 'AUTH.FORGOT_PASSWORD',
        'value' => 'Forgot your password?',
    ]);

    // 3. Tenant-specific override of AUTH.LOGIN
    Translation::create([
        'tenant_id' => $this->tenant->id,
        'locale' => 'en',
        'key' => 'AUTH.LOGIN',
        'value' => 'Custom Acme Login',
    ]);

    // 4. Tenant-specific exclusive key
    Translation::create([
        'tenant_id' => $this->tenant->id,
        'locale' => 'en',
        'key' => 'ACME.BANNER',
        'value' => 'Welcome Acme User',
    ]);

    // Other tenant key that must NOT appear
    $otherTenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Other Corp',
        'legal_name' => 'Other Corp S.r.l.',
        'vat_number' => 'IT99999999999',
        'status' => 'active',
    ]);

    Translation::create([
        'tenant_id' => $otherTenant->id,
        'locale' => 'en',
        'key' => 'OTHER.ONLY',
        'value' => 'Other Tenant Value',
    ]);

    // The overrides of a tenant are served to its signed-in members only; a guest naming the tenant gets the global ones.
    $member = User::factory()->create();
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $this->getJson("/api/v1/translations/en?tenant_id={$this->tenant->id}")->assertOk()->assertJson(['tenant_id' => null]);
    Cache::flush();

    $response = $this->actingAs($member, 'sanctum')->getJson("/api/v1/translations/en?tenant_id={$this->tenant->id}");

    $response->assertOk()
        ->assertJson([
            'locale' => 'en',
            'tenant_id' => $this->tenant->id,
        ]);

    $translations = $response->json('translations');

    // Tenant specific overrides default
    expect($translations['AUTH.LOGIN'])->toBe('Custom Acme Login')
        // Global null is fallback
        ->and($translations['AUTH.FORGOT_PASSWORD'])->toBe('Forgot your password?')
        // Tenant exclusive is present
        ->and($translations['ACME.BANNER'])->toBe('Welcome Acme User')
        // Other tenant key is absent
        ->and(array_key_exists('OTHER.ONLY', $translations))->toBeFalse()
        // No incomplete class serialization
        ->and(array_key_exists('__PHP_Incomplete_Class_Name', $translations))->toBeFalse();
});

test('NormalizeTenantInput middleware normalizes tenant_id from header and query', function (): void {
    Cache::flush();

    Translation::create([
        'tenant_id' => null,
        'locale' => 'en',
        'key' => 'HEADER.TEST',
        'value' => 'Header Test Passed',
    ]);

    // Test with X-Tenant-Id: default header
    $response = $this->withHeaders([
        'X-Tenant-Id' => 'default',
    ])->getJson('/api/v1/translations/en');

    $response->assertOk()
        ->assertJson([
            'locale' => 'en',
            'tenant_id' => null,
        ]);

    $translations = $response->json('translations');
    expect($translations['HEADER.TEST'])->toBe('Header Test Passed');
});

test('NormalizeTenantInput normalizes tenant_id in authenticated storeOrUpdate request', function (): void {
    Cache::flush();

    // Writing the global dictionary is reserved to platform administrators.
    $user = User::factory()->create();
    $user->forceFill(['is_platform_admin' => true])->save();
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    Sanctum::actingAs($user);

    $response = $this->withHeaders([
        'X-Tenant-Id' => $this->tenant->id,
    ])->postJson('/api/v1/translations', [
        'tenant_id' => 'default',
        'locale' => 'en',
        'key' => 'DYNAMIC.SAVED',
        'value' => 'Saved Global Translation',
    ]);

    $response->assertOk();

    // The saved translation must have tenant_id = null
    $saved = Translation::where('key', 'DYNAMIC.SAVED')->first();
    expect($saved)->not->toBeNull()
        ->and($saved->tenant_id)->toBeNull()
        ->and($saved->value)->toBe('Saved Global Translation');
});

test('Translation scopeForTenant implements fallback query condition correctly', function (): void {
    Translation::create([
        'tenant_id' => null,
        'locale' => 'en',
        'key' => 'GLOBAL.KEY',
        'value' => 'Global Value',
    ]);

    Translation::create([
        'tenant_id' => $this->tenant->id,
        'locale' => 'en',
        'key' => 'TENANT.KEY',
        'value' => 'Tenant Value',
    ]);

    // When tenant_id is null: selects whereNull('tenant_id')
    $nullResults = Translation::forTenant(null)->where('locale', 'en')->pluck('key')->all();
    expect($nullResults)->toContain('GLOBAL.KEY')
        ->and($nullResults)->not->toContain('TENANT.KEY');

    // When tenant_id is provided: selects where tenant_id = :id or tenant_id is null
    $tenantResults = Translation::forTenant($this->tenant->id)->where('locale', 'en')->pluck('key')->all();
    expect($tenantResults)->toContain('GLOBAL.KEY')
        ->and($tenantResults)->toContain('TENANT.KEY');
});

test('translations endpoint returns empty json object when no translations exist', function (): void {
    Cache::flush();

    $response = $this->getJson('/api/v1/translations/de?tenant_id=default');

    $response->assertOk()
        ->assertJson([
            'locale' => 'de',
            'tenant_id' => null,
            'translations' => [],
        ]);

    // Ensure raw json string contains "translations":{}
    expect($response->getContent())->toContain('"translations":{}');
});

test('an empty dictionary is not cached, so translations seeded later are served', function (): void {
    Cache::flush();

    $this->getJson('/api/v1/translations/de?tenant_id=default')->assertOk();
    expect(Cache::has('translations_global_de'))->toBeFalse();

    Translation::create(['tenant_id' => null, 'locale' => 'de', 'key' => 'buttons.save', 'value' => 'Speichern']);

    $this->getJson('/api/v1/translations/de?tenant_id=default')
        ->assertOk()
        ->assertJsonPath('translations', ['buttons.save' => 'Speichern']);
});

test('TranslationSeeder drops the cached global dictionaries', function (): void {
    Cache::forever('translations_global_en', ['buttons.save' => 'stale']);

    $this->seed(TranslationSeeder::class);

    expect(Cache::has('translations_global_en'))->toBeFalse();
});

test('various tenant_id alias formats are normalized to null', function (string $alias): void {
    Cache::flush();

    $response = $this->getJson("/api/v1/translations/en?tenant_id={$alias}");

    $response->assertOk()
        ->assertJson([
            'locale' => 'en',
            'tenant_id' => null,
        ]);
})->with(['default', 'DEFAULT', 'Default', 'null', 'undefined', '']);
