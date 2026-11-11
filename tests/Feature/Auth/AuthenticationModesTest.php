<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    $this->password = 'Bb2!'.Str::random(16);
    $this->tenant = Tenant::create([
        'name' => 'Studio Bianchi',
        'legal_name' => 'Studio Bianchi SRL',
        'fiscal_code' => '12345678901',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    $this->user = User::create([
        'name' => 'Giulia Bianchi',
        'email' => 'giulia.bianchi@example.com',
        'password' => Hash::make($this->password),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
});

function tokenLogin(string $email, string $password, string $userAgent = 'FatturaFacile Mobile/1.0'): string
{
    return (string) test()->withHeader('User-Agent', $userAgent)
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'token_mode' => true])
        ->assertOk()
        ->json('data.token');
}

test('a client that asks for the token mode gets an expiring Bearer token named after the client', function (): void {
    config(['auth.token_mode.enabled' => true]);

    $response = $this->withHeader('User-Agent', 'FatturaFacile Mobile/1.0')
        ->postJson('/api/v1/auth/login', ['email' => 'giulia.bianchi@example.com', 'password' => $this->password, 'token_mode' => true])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'token')
        ->assertJsonPath('data.token_type', 'Bearer');

    $token = PersonalAccessToken::findToken($response->json('data.token'));

    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('FatturaFacile Mobile/1.0')
        ->and($token->expires_at)->not->toBeNull()
        ->and($response->json('data.expires_at'))->not->toBeNull();
});

test('the SPA logs in with an HttpOnly session cookie and never receives a token', function (): void {
    $this->withHeaders(spaHeaders())->get('/sanctum/csrf-cookie')->assertNoContent();

    $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => 'giulia.bianchi@example.com', 'password' => $this->password, 'tenant_id' => $this->tenant->id])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_type');

    expect(PersonalAccessToken::count())->toBe(0);

    $this->withHeaders([...spaHeaders(), 'X-Tenant-Id' => $this->tenant->id])
        ->getJson('/api/v1/auth/profile')
        ->assertOk()
        ->assertJsonPath('data.email', 'giulia.bianchi@example.com');

    // Refreshing a cookie session renews it and still issues no token.
    $this->withHeaders([...spaHeaders(), 'X-Tenant-Id' => $this->tenant->id])
        ->postJson('/api/v1/auth/refresh')
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonMissingPath('data.token');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('the SPA completes a login with two factors in cookie mode', function (): void {
    $totp = app(Totp::class);
    $secret = $totp->generateSecret();
    $this->user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => [],
    ])->save();

    $this->withHeaders(spaHeaders())->get('/sanctum/csrf-cookie');
    $challenge = $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => 'giulia.bianchi@example.com', 'password' => $this->password])
        ->assertOk()
        ->assertJsonPath('data.two_factor_required', true)
        ->json('data.challenge_token');

    $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $totp->codeAt($secret, time())])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonMissingPath('data.token');

    $this->withHeaders([...spaHeaders(), 'X-Tenant-Id' => $this->tenant->id])->getJson('/api/v1/auth/profile')->assertOk();
});

test('refreshing a token rotates it: the old one stops working', function (): void {
    config(['auth.token_mode.enabled' => true]);

    $old = tokenLogin('giulia.bianchi@example.com', $this->password);

    $new = $this->withHeaders(['Authorization' => "Bearer {$old}", 'X-Tenant-Id' => $this->tenant->id])
        ->postJson('/api/v1/auth/refresh')
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'token')
        ->json('data.token');

    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$old}", 'X-Tenant-Id' => $this->tenant->id])->getJson('/api/v1/auth/profile')->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$new}", 'X-Tenant-Id' => $this->tenant->id])->getJson('/api/v1/auth/profile')->assertOk();
});

test('a user lists and revokes their own tokens and signs out everywhere else', function (): void {
    config(['auth.token_mode.enabled' => true]);

    $phone = tokenLogin('giulia.bianchi@example.com', $this->password, 'Phone');
    $laptop = tokenLogin('giulia.bianchi@example.com', $this->password, 'Laptop');
    tokenLogin('giulia.bianchi@example.com', $this->password, 'Tablet');
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $this->user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Browser', 'payload' => '', 'last_activity' => time()]);
    $headers = ['Authorization' => "Bearer {$laptop}", 'X-Tenant-Id' => $this->tenant->id];

    $sessions = collect($this->withHeaders($headers)->getJson('/api/v1/auth/sessions')->assertOk()->json('data'));

    expect($sessions)->toHaveCount(4)
        ->and($sessions->where('current', true)->pluck('name')->all())->toBe(['Laptop'])
        ->and($sessions->where('kind', 'session')->first()['id'])->toHaveLength(64);

    $phoneId = $sessions->firstWhere('name', 'Phone')['id'];
    $this->withHeaders($headers)->deleteJson("/api/v1/auth/sessions/token/{$phoneId}")->assertOk();
    $this->withHeaders($headers)->deleteJson("/api/v1/auth/sessions/token/{$phoneId}")->assertNotFound();

    $this->withHeaders($headers)->postJson('/api/v1/auth/sessions/revoke-others', ['current_password' => 'wrong'])->assertUnprocessable();
    $this->withHeaders($headers)->postJson('/api/v1/auth/sessions/revoke-others', ['current_password' => $this->password])
        ->assertOk()
        ->assertJsonPath('data.tokens', 1)
        ->assertJsonPath('data.sessions', 1);

    expect($this->user->tokens()->pluck('name')->all())->toBe(['Laptop'])
        ->and(DB::table('sessions')->where('user_id', $this->user->id)->count())->toBe(0);

    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$phone}", 'X-Tenant-Id' => $this->tenant->id])->getJson('/api/v1/auth/profile')->assertUnauthorized();
});

test('a user cannot revoke the tokens of another user', function (): void {
    config(['auth.token_mode.enabled' => true]);

    $other = User::create(['name' => 'Altro', 'email' => 'altro@example.com', 'password' => Hash::make($this->password), 'is_active' => true, 'email_verified_at' => now()]);
    $other->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $otherToken = $other->createToken('Other')->accessToken;
    $mine = tokenLogin('giulia.bianchi@example.com', $this->password);

    $this->withHeaders(['Authorization' => "Bearer {$mine}", 'X-Tenant-Id' => $this->tenant->id])
        ->deleteJson("/api/v1/auth/sessions/token/{$otherToken->id}")
        ->assertNotFound();

    expect($other->tokens()->count())->toBe(1);
});

test('deactivating a user revokes every token and browser session', function (): void {
    config(['auth.token_mode.enabled' => true]);

    tokenLogin('giulia.bianchi@example.com', $this->password);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $this->user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);

    $this->user->update(['is_active' => false]);

    expect($this->user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->user->id)->count())->toBe(0);
});

test('turning two factors on ends the other accesses opened with the password alone', function (): void {
    config(['auth.token_mode.enabled' => true]);

    tokenLogin('giulia.bianchi@example.com', $this->password, 'Old phone');
    $current = tokenLogin('giulia.bianchi@example.com', $this->password, 'Laptop');
    $headers = ['Authorization' => "Bearer {$current}", 'X-Tenant-Id' => $this->tenant->id];

    $secret = $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/enable', ['current_password' => $this->password])->json('data.secret');
    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Totp::class)->codeAt($secret, time())])->assertOk();

    expect($this->user->tokens()->pluck('name')->all())->toBe(['Laptop']);
});

test('session cookies are encrypted, HttpOnly, Secure and SameSite in every environment', function (): void {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.encrypt'))->toBeTrue()
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');

    // No environment (local and testing included) decides the Secure flag any more.
    expect(file_get_contents(base_path('config/session.php')))->not->toContain("'local', 'testing'");
});
