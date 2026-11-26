<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Services\Auth\Security\BreachedPasswords;
use App\Services\Auth\Security\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create();
    $this->member = User::create([
        'name' => 'Marco Neri', 'email' => 'marco.neri@example.com', 'password' => Hash::make('Original-Passw0rd!'),
        'locale' => 'it', 'is_active' => true, 'email_verified_at' => now(),
    ]);
    $this->member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
});

function pwnedRange(string $password, int $count = 1337): string
{
    $hash = strtoupper(sha1($password));

    return implode("\r\n", [
        '0018A45C4D1DEF81644B54AB7F969B88D65:1',
        substr($hash, 5).':'.$count,
        '00D4F6E8FA6EECAD2A3AA415EEC418D38EC:2',
    ]);
}

function pwnedPrefix(string $password): string
{
    return substr(strtoupper(sha1($password)), 0, 5);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function breachedPolicy(Tenant $tenant, array $attributes = []): TenantSecurityPolicy
{
    return TenantSecurityPolicy::factory()->create(array_merge(['tenant_id' => $tenant->id, 'password_check_breached' => true, 'password_history_count' => 0], $attributes));
}

test('a password found in the range response is breached; padding and other suffixes are not', function (): void {
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(pwnedRange('Winter2025!'), 200)]);

    expect(app(BreachedPasswords::class)->isBreached('Winter2025!'))->toBeTrue();

    Cache::flush();
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(pwnedRange('Winter2025!', 0), 200)]);
    expect(app(BreachedPasswords::class)->isBreached('Winter2025!'))->toBeFalse();

    Cache::flush();
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n", 200)]);
    expect(app(BreachedPasswords::class)->isBreached('Some-Other-Passw0rd!'))->toBeFalse();
});

test('only the first five characters of the hash leave, with padding asked for', function (): void {
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(pwnedRange('Winter2025!'), 200)]);

    app(BreachedPasswords::class)->isBreached('Winter2025!');

    $hash = strtoupper(sha1('Winter2025!'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
        && $request->hasHeader('Add-Padding', 'true')
        && ! str_contains($request->url(), substr($hash, 5))
        && ! str_contains(json_encode($request->data()) ?: '', 'Winter2025'));
    Http::assertSentCount(1);
});

test('the answer for a prefix is cached', function (): void {
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(pwnedRange('Winter2025!'), 200)]);
    $service = app(BreachedPasswords::class);

    expect($service->isBreached('Winter2025!'))->toBeTrue()->and($service->isBreached('Winter2025!'))->toBeTrue();
    Http::assertSentCount(1);
});

test('the check fails open and reports when the service is down', function (): void {
    Exceptions::fake();
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 503)]);
    expect(app(BreachedPasswords::class)->isBreached('Winter2025!'))->toBeFalse();

    Cache::flush();
    Http::fake(fn () => throw new ConnectionException('timeout'));
    expect(app(BreachedPasswords::class)->isBreached('Winter2025!'))->toBeFalse();

    Exceptions::assertReportedCount(2);
});

test('the policy refuses a breached password at change, with the breached message', function (): void {
    breachedPolicy($this->tenant);
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(pwnedRange('Winter2025-Sun!'), 200)]);
    Sanctum::actingAs($this->member);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/auth/password', [
        'current_password' => 'Original-Passw0rd!', 'password' => 'Winter2025-Sun!', 'password_confirmation' => 'Winter2025-Sun!',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(Hash::check('Original-Passw0rd!', $this->member->fresh()->password))->toBeTrue();

    $unlisted = 'Unlisted-Phrase-8472!';
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n", 200)]);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/auth/password', [
        'current_password' => 'Original-Passw0rd!', 'password' => $unlisted, 'password_confirmation' => $unlisted,
    ])->assertOk();
});

test('without the setting nothing is sent to the service', function (): void {
    Http::fake();

    expect(app(PasswordPolicy::class)->violations($this->member, 'Winter2025-Sun!'))->toBe([]);
    Http::assertNothingSent();
});

test('the system default turns the check on for tenants without a policy', function (): void {
    config(['auth.registration.password.check_breached' => true]);
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(pwnedRange('Winter2025-Sun!'), 200)]);

    expect(app(PasswordPolicy::class)->violations($this->member, 'Winter2025-Sun!'))->toHaveCount(1);
});

test('a password that already breaks another rule is not sent to the service', function (): void {
    breachedPolicy($this->tenant);
    Http::fake();

    expect(app(PasswordPolicy::class)->violations($this->member, 'short'))->not->toBeEmpty();
    Http::assertNothingSent();
});

test('the public check returns the checklist and the strength', function (): void {
    $weak = $this->postJson('/api/v1/auth/password-policy/check', ['password' => 'abc'])->assertOk();
    expect(collect($weak->json('data.rules'))->pluck('code')->all())->toBe(['min_length', 'mixed_case', 'numbers', 'symbols'])
        ->and(collect($weak->json('data.rules'))->firstWhere('code', 'min_length')['passed'])->toBeFalse()
        ->and($weak->json('data.acceptable'))->toBeFalse()
        ->and($weak->json('data.strength'))->toBe(1);

    $strong = $this->postJson('/api/v1/auth/password-policy/check', ['password' => 'Correct-Horse-9-Battery'])->assertOk();
    expect(collect($strong->json('data.rules'))->every(fn (array $rule): bool => $rule['passed']))->toBeTrue()
        ->and($strong->json('data.acceptable'))->toBeTrue()
        ->and($strong->json('data.strength'))->toBe(4);

    $this->postJson('/api/v1/auth/password-policy/check', ['password' => ''])->assertOk()->assertJsonPath('data.strength', 0)->assertJsonPath('data.acceptable', false);
    $this->postJson('/api/v1/auth/password-policy/check', [])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/auth/password-policy/check', ['password' => 'x', 'tenant_id' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
});

test('the check follows the policy of the tenant, including the breached rule', function (): void {
    breachedPolicy($this->tenant, ['password_min_length' => 14]);
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(pwnedRange('Winter2025-Sun!'), 200)]);

    $short = $this->postJson('/api/v1/auth/password-policy/check', ['password' => 'Winter-2025!', 'tenant_id' => $this->tenant->id])->assertOk();
    $min = collect($short->json('data.rules'))->firstWhere('code', 'min_length');
    expect($min['passed'])->toBeFalse()->and($min['message'])->toContain('14');
    Http::assertNothingSent();

    $breached = $this->postJson('/api/v1/auth/password-policy/check', ['password' => 'Winter2025-Sun!', 'tenant_id' => $this->tenant->id])->assertOk();
    expect(collect($breached->json('data.rules'))->firstWhere('code', 'breached')['passed'])->toBeFalse()
        ->and($breached->json('data.acceptable'))->toBeFalse()
        ->and($breached->json('data.strength'))->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.pwnedpasswords.com/range/'.pwnedPrefix('Winter2025-Sun!'));

    // The policy can be found through the email of a member too; an unknown tenant or email means the platform defaults.
    $viaEmail = $this->postJson('/api/v1/auth/password-policy/check', ['password' => 'Winter-2025!', 'email' => 'MARCO.neri@example.com'])->assertOk();
    expect(collect($viaEmail->json('data.rules'))->firstWhere('code', 'min_length')['passed'])->toBeFalse();
    $unknown = $this->postJson('/api/v1/auth/password-policy/check', ['password' => 'Winter-2025!', 'tenant_id' => (string) Str::uuid(), 'email' => 'nobody@example.com'])->assertOk();
    expect(collect($unknown->json('data.rules'))->firstWhere('code', 'min_length')['passed'])->toBeTrue()
        ->and(collect($unknown->json('data.rules'))->pluck('code')->all())->not->toContain('breached');
});

test('the public rules and the rules of the signed-in user are exposed', function (): void {
    breachedPolicy($this->tenant, ['password_min_length' => 14, 'password_history_count' => 5, 'password_max_age_days' => 90]);

    $this->getJson('/api/v1/auth/password-policy/rules')->assertOk()
        ->assertJsonPath('data.min_length', 10)->assertJsonPath('data.check_breached', false)->assertJsonPath('data.history_count', null);
    $this->getJson('/api/v1/auth/password-policy/rules?tenant_id='.$this->tenant->id)->assertOk()
        ->assertJsonPath('data.min_length', 14)->assertJsonPath('data.check_breached', true)->assertJsonPath('data.rules', ['min_length', 'mixed_case', 'numbers', 'symbols', 'breached']);
    $this->getJson('/api/v1/auth/password-policy/rules?tenant_id=nope')->assertUnprocessable();

    $this->getJson('/api/v1/auth/password-policy')->assertUnauthorized();

    Sanctum::actingAs($this->member);
    $this->getJson('/api/v1/auth/password-policy')->assertOk()
        ->assertJsonPath('data.min_length', 14)->assertJsonPath('data.history_count', 5)->assertJsonPath('data.max_age_days', 90)
        ->assertJsonPath('data.rules', ['min_length', 'mixed_case', 'numbers', 'symbols', 'reused', 'breached']);
});
