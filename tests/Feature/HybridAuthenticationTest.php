<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Enterprise',
        'legal_name' => 'FatturaFacile SRL',
        'fiscal_code' => '12345678901',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario.rossi@example.com',
        'password' => Hash::make('Password123!'),
        'locale' => 'it',
        'is_active' => true,
    ]);

    $this->user->tenants()->attach($this->tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'admin',
    ]);
});

test('external client logs in with bearer token, accesses protected route and logs out', function (): void {
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'mario.rossi@example.com',
        'password' => 'Password123!',
        'tenant_id' => $this->tenant->id,
    ]);

    $loginResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Authenticated successfully',
        ])
        ->assertJsonStructure([
            'data' => [
                'token',
                'token_type',
                'user' => ['id', 'name', 'email'],
                'active_tenant' => ['id', 'name'],
            ],
        ]);

    $token = $loginResponse->json('data.token');
    expect($token)->not->toBeEmpty();
    expect(PersonalAccessToken::count())->toBe(1);

    // Access profile using Bearer token
    $profileResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Tenant-ID', $this->tenant->id)
        ->getJson('/api/v1/auth/profile');

    $profileResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'email' => 'mario.rossi@example.com',
            ],
        ]);

    // Logout external client
    $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout');

    $logoutResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Successfully logged out',
        ]);

    // Token should be revoked
    expect(PersonalAccessToken::count())->toBe(0);
});

test('angular spa performs stateful session login, accesses profile and logs out cleanly', function (): void {
    // Angular development origin
    $origin = 'http://localhost:4200';

    // 1. Fetch CSRF cookie
    $csrfResponse = $this->get('/sanctum/csrf-cookie', [
        'Origin' => $origin,
        'Referer' => "{$origin}/",
    ]);
    $csrfResponse->assertStatus(204);

    // 2. Perform stateful login
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'mario.rossi@example.com',
        'password' => 'Password123!',
        'tenant_id' => $this->tenant->id,
    ], [
        'Origin' => $origin,
        'Referer' => "{$origin}/",
    ]);

    $loginResponse->assertStatus(200);

    // 3. Access protected route using session cookies without Bearer header
    $profileResponse = $this->withHeader('X-Tenant-ID', $this->tenant->id)
        ->withHeaders([
            'Origin' => $origin,
            'Referer' => "{$origin}/",
        ])
        ->getJson('/api/v1/auth/profile');

    $profileResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'email' => 'mario.rossi@example.com',
            ],
        ]);

    // 4. Stateful logout
    $logoutResponse = $this->withHeaders([
        'Origin' => $origin,
        'Referer' => "{$origin}/",
    ])->postJson('/api/v1/auth/logout');

    $logoutResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Successfully logged out',
        ]);
});

test('sanctum stateful domains configuration includes angular localhost 4200', function (): void {
    $statefulDomains = config('sanctum.stateful');

    expect($statefulDomains)->toBeArray()
        ->and($statefulDomains)->toContain('localhost:4200')
        ->and($statefulDomains)->toContain('127.0.0.1:4200');
});

test('cors configuration supports credentials and allows angular frontend origins', function (): void {
    expect(config('cors.supports_credentials'))->toBeTrue();

    $allowedOrigins = config('cors.allowed_origins');
    expect($allowedOrigins)->toBeArray()
        ->and($allowedOrigins)->toContain('http://localhost:4200');
});
