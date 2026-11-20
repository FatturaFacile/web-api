<?php

declare(strict_types=1);

use App\Models\MobileDeviceSession;
use App\Models\PayslipDocument;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSite;
use App\Services\Mobile\DeviceSessionService;
use App\Services\Mobile\PkceAuthorizationService;
use App\Services\Mobile\ReceiptTextParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The mobile app signs in through the regular login in token mode.
    config(['auth.token_mode.enabled' => true]);

    $this->tenant = Tenant::create([
        'name' => 'Mobile Tenant',
        'legal_name' => 'Mobile Tenant SA',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $this->user = User::create([
        'name' => 'Giulia Bianchi',
        'email' => 'giulia@example.com',
        'password' => Hash::make('Password123!'),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->verifier = Str::random(64);

    /** Full PKCE login; returns [token, device_session_id]. */
    $this->mobileLogin = function (string $deviceId = 'device-1', array $extra = []): array {
        $code = $this->postJson('/api/v1/mobile/auth/pkce/authorize', [
            'email' => 'giulia@example.com',
            'password' => 'Password123!',
            'code_challenge' => PkceAuthorizationService::challengeFor($this->verifier),
            'code_challenge_method' => 'S256',
        ])->assertOk()->json('data.code');

        $response = $this->postJson('/api/v1/mobile/auth/pkce/token', array_merge([
            'code' => $code,
            'code_verifier' => $this->verifier,
            'device_id' => $deviceId,
            'platform' => 'android',
            'app_version' => '1.2.0',
        ], $extra))->assertCreated();

        return [$response->json('data.access_token'), $response->json('data.device_session_id')];
    };

    $this->api = fn (string $token) => $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $this->tenant->id,
        'X-Device-Id' => 'device-1',
    ]);
});

test('PKCE flow issues a device-bound token and rejects a wrong verifier or a reused code', function (): void {
    [$token, $deviceSessionId] = ($this->mobileLogin)();

    expect($token)->not->toBeEmpty();
    expect(MobileDeviceSession::withoutGlobalScopes()->find($deviceSessionId))
        ->platform->toBe('android')
        ->access_token_id->not->toBeNull();

    $code = $this->postJson('/api/v1/mobile/auth/pkce/authorize', [
        'email' => 'giulia@example.com',
        'password' => 'Password123!',
        'code_challenge' => PkceAuthorizationService::challengeFor($this->verifier),
        'code_challenge_method' => 'S256',
    ])->json('data.code');

    $this->postJson('/api/v1/mobile/auth/pkce/token', [
        'code' => $code,
        'code_verifier' => Str::random(64),
        'device_id' => 'device-x',
        'platform' => 'ios',
    ])->assertStatus(400);

    // the code is burnt even after a failed attempt
    $this->postJson('/api/v1/mobile/auth/pkce/token', [
        'code' => $code,
        'code_verifier' => $this->verifier,
        'device_id' => 'device-x',
        'platform' => 'ios',
    ])->assertStatus(400);
});

test('PKCE authorize rejects plain method and bad credentials', function (): void {
    $this->postJson('/api/v1/mobile/auth/pkce/authorize', [
        'email' => 'giulia@example.com',
        'password' => 'Password123!',
        'code_challenge' => PkceAuthorizationService::challengeFor($this->verifier),
        'code_challenge_method' => 'plain',
    ])->assertStatus(422);

    $this->postJson('/api/v1/mobile/auth/pkce/authorize', [
        'email' => 'giulia@example.com',
        'password' => 'wrong',
        'code_challenge' => PkceAuthorizationService::challengeFor($this->verifier),
        'code_challenge_method' => 'S256',
    ])->assertStatus(401);
});

test('mobile tenant list returns only the tenants the user belongs to', function (): void {
    Tenant::create(['name' => 'Foreign Tenant', 'legal_name' => 'Foreign SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    [$token] = ($this->mobileLogin)();

    $response = ($this->api)($token)->getJson('/api/v1/mobile/tenants')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.tenant_id'))->toBe($this->tenant->id)
        ->and($response->json('data.0.tenant_name'))->toBe('Mobile Tenant');
});

test('bootstrap payload returns workspaces, badges and sync plan', function (): void {
    [$token] = ($this->mobileLogin)();

    $response = ($this->api)($token)->getJson('/api/v1/mobile/bootstrap')->assertOk();

    expect($response->json('data.user.email'))->toBe('giulia@example.com')
        ->and($response->json('data.workspaces'))->toContain('employee', 'executive', 'warehouse')
        ->and($response->json('data.device.device_id'))->toBe('device-1')
        ->and($response->json('data.app.update_required'))->toBeFalse()
        ->and($response->json('data.sync.entities'))->toContain('products', 'work_sites');
});

test('push token registration and biometric flag are stored on the device session', function (): void {
    [$token, $id] = ($this->mobileLogin)();

    ($this->api)($token)->putJson('/api/v1/mobile/devices/current/push-token', ['push_token' => 'fcm-abc'])->assertOk();
    ($this->api)($token)->putJson('/api/v1/mobile/devices/current/biometric', ['biometric_enrolled' => true])->assertOk();

    $device = MobileDeviceSession::withoutGlobalScopes()->find($id);
    expect($device->push_token)->toBe('fcm-abc')
        ->and($device->push_provider)->toBe('fcm')
        ->and($device->biometric_enrolled)->toBeTrue();
});

test('revoked device is blocked everywhere, asked to wipe, and the token dies on acknowledgement', function (): void {
    [$token, $id] = ($this->mobileLogin)();
    $device = MobileDeviceSession::withoutGlobalScopes()->find($id);

    app(DeviceSessionService::class)->revoke($device, 'employee_offboarding');

    ($this->api)($token)->getJson('/api/v1/auth/profile')
        ->assertStatus(401)
        ->assertHeader('X-Mobile-Wipe', 'required');

    ($this->api)($token)->postJson('/api/v1/mobile/devices/current/wipe-ack')->assertOk();

    expect(PersonalAccessToken::count())->toBe(0);
});

test('offboarding revokes every device and deletes unbound tokens', function (): void {
    [$token] = ($this->mobileLogin)();
    $this->user->createToken('web-session');

    $count = app(DeviceSessionService::class)->revokeAllForUser($this->user->id, 'employee_offboarding');

    expect($count)->toBe(1)
        ->and(PersonalAccessToken::count())->toBe(1); // only the device-bound one waits for the wipe ack

    ($this->api)($token)->getJson('/api/v1/mobile/bootstrap')->assertStatus(401);
});

test('a user can revoke their own lost device but not someone else\'s', function (): void {
    [$token, $id] = ($this->mobileLogin)();
    [, $otherId] = ($this->mobileLogin)('device-2');

    ($this->api)($token)->deleteJson("/api/v1/mobile/devices/{$otherId}")->assertOk();
    expect(MobileDeviceSession::withoutGlobalScopes()->find($otherId)->revoke_reason)->toBe('lost_or_stolen');

    $stranger = User::create(['name' => 'Luca', 'email' => 'luca@example.com', 'password' => Hash::make('x'), 'is_active' => true]);
    $stranger->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $strangerToken = $stranger->createToken('t')->plainTextToken;
    app('auth')->forgetGuards(); // the Sanctum guard caches the user across requests of one test

    $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken, 'X-Tenant-Id' => $this->tenant->id])
        ->deleteJson("/api/v1/mobile/devices/{$id}")->assertForbidden();
});

test('delta sync returns changed rows, tombstones and respects updated_since', function (): void {
    [$token] = ($this->mobileLogin)();

    $site = WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'HQ Lugano', 'latitude' => 46.0037, 'longitude' => 8.9511, 'radius_meters' => 200]);
    $gone = WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'Old', 'latitude' => 46.1, 'longitude' => 8.9, 'radius_meters' => 100]);
    $gone->delete();

    $full = ($this->api)($token)->getJson('/api/v1/mobile/sync/work_sites')->assertOk();
    expect(collect($full->json('data.data'))->pluck('name')->all())->toBe(['HQ Lugano'])
        ->and($full->json('data.deleted'))->toBe([$gone->id])
        ->and($full->json('data.has_more'))->toBeFalse();

    $since = now()->addMinute()->toIso8601String();
    $empty = ($this->api)($token)->getJson('/api/v1/mobile/sync/work_sites?updated_since='.urlencode($since))->assertOk();
    expect($empty->json('data.data'))->toBe([])->and($empty->json('data.deleted'))->toBe([]);

    ($this->api)($token)->getJson('/api/v1/mobile/sync/unknown')->assertNotFound();

    // the manifest is refreshed after a completed pass
    expect(MobileDeviceSession::withoutGlobalScopes()->first()->manifests()->withoutGlobalScopes()->where('entity_name', 'work_sites')->exists())->toBeTrue();
});

test('delta sync paginates with a stable cursor', function (): void {
    [$token] = ($this->mobileLogin)();
    foreach (range(1, 5) as $i) {
        WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => "Site {$i}", 'latitude' => 46, 'longitude' => 9, 'radius_meters' => 100]);
    }

    $seen = [];
    $cursor = null;
    do {
        $url = '/api/v1/mobile/sync/work_sites?limit=2'.($cursor ? '&cursor='.urlencode($cursor) : '');
        $page = ($this->api)($token)->getJson($url)->assertOk();
        $seen = array_merge($seen, collect($page->json('data.data'))->pluck('name')->all());
        $cursor = $page->json('data.next_cursor');
    } while ($cursor !== null);

    expect($seen)->toHaveCount(5)->and(array_unique($seen))->toHaveCount(5);
});

test('geofence blocks mobile clock-in outside the work site and allows it inside', function (): void {
    [$token] = ($this->mobileLogin)();
    WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'HQ', 'latitude' => 46.0037, 'longitude' => 8.9511, 'radius_meters' => 150]);

    ($this->api)($token)->postJson('/api/v1/time-attendance/clock-in', [])->assertStatus(422);
    ($this->api)($token)->postJson('/api/v1/time-attendance/clock-in', ['latitude' => 45.4642, 'longitude' => 9.19])->assertStatus(422);
    ($this->api)($token)->postJson('/api/v1/time-attendance/clock-in', ['latitude' => 46.0040, 'longitude' => 8.9513])->assertCreated();

    expect(TimeEntry::count())->toBe(1);
});

test('clock-in without work sites keeps working for non geofenced tenants', function (): void {
    [$token] = ($this->mobileLogin)();

    ($this->api)($token)->postJson('/api/v1/time-attendance/clock-in', [])->assertCreated();
});

test('idempotency key replays offline writes without duplicating them', function (): void {
    [$token] = ($this->mobileLogin)();

    $first = ($this->api)($token)->withHeader('Idempotency-Key', 'offline-1')->postJson('/api/v1/time-attendance/clock-in', []);
    $second = ($this->api)($token)->withHeader('Idempotency-Key', 'offline-1')->postJson('/api/v1/time-attendance/clock-in', []);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(TimeEntry::count())->toBe(1)->and($second->json('data.id'))->toBe($first->json('data.id'));
});

test('payslip is published, listed, downloaded once with a read receipt', function (): void {
    Storage::fake('local');
    [$token] = ($this->mobileLogin)();

    $created = ($this->api)($token)->post('/api/v1/mobile/admin/payslips', [
        'user_id' => $this->user->id,
        'document_type' => 'lohnausweis',
        'period_year' => 2026,
        'title' => 'Lohnausweis 2026',
        'country' => 'CH',
        'file' => UploadedFile::fake()->create('lohnausweis.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $id = $created->json('data.id');
    expect(($this->api)($token)->getJson('/api/v1/mobile/bootstrap')->json('data.badges.unread_documents'))->toBe(1);

    ($this->api)($token)->getJson('/api/v1/mobile/payslips?type=lohnausweis')->assertOk()->assertJsonCount(1, 'data');
    ($this->api)($token)->get("/api/v1/mobile/payslips/{$id}/download")->assertOk();

    $doc = PayslipDocument::find($id);
    expect($doc->read_at)->not->toBeNull()->and($doc->read_device_id)->toBe('device-1');
    expect(($this->api)($token)->getJson('/api/v1/mobile/bootstrap')->json('data.badges.unread_documents'))->toBe(0);
});

test('payslips of other employees are not reachable', function (): void {
    [$token] = ($this->mobileLogin)();
    $other = User::create(['name' => 'Altro', 'email' => 'altro@example.com', 'password' => Hash::make('x'), 'is_active' => true]);
    $doc = PayslipDocument::create([
        'tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'document_type' => 'payslip',
        'period_year' => 2026, 'period_month' => 1, 'title' => 'x', 'file_path' => 'x.pdf', 'published_at' => now(),
    ]);

    ($this->api)($token)->postJson("/api/v1/mobile/payslips/{$doc->id}/read-receipt")->assertNotFound();
});

test('receipt parser extracts Italian and Swiss receipt fields', function (): void {
    $parser = new ReceiptTextParser;

    $it = $parser->parse("BAR TRATTORIA DA MARIO\nP.IVA 01234567890\n12/03/2026\nCoperto 2,00\nSubtotale 20,00\nIVA 10% 2,00\nTOTALE EURO 22,00");
    expect($it)->toMatchArray(['vendor' => 'BAR TRATTORIA DA MARIO', 'date' => '2026-03-12', 'total' => 22.0, 'currency' => 'EUR', 'vat_rate' => 10.0, 'vat_number' => 'IT01234567890']);

    $ch = $parser->parse("Migros Lugano\nCHE-123.456.789 MWST\n05.11.2026\nTotal CHF 1'234.50\nMWST 8.1% 92.50");
    expect($ch)->toMatchArray(['vendor' => 'Migros Lugano', 'date' => '2026-11-05', 'total' => 1234.5, 'currency' => 'CHF', 'vat_rate' => 8.1]);
});

test('receipt parse endpoint is authenticated and returns parsed data', function (): void {
    $this->postJson('/api/v1/mobile/receipts/parse', ['text' => 'x'])->assertUnauthorized();

    [$token] = ($this->mobileLogin)();
    ($this->api)($token)->postJson('/api/v1/mobile/receipts/parse', ['text' => "Shop\nTOTALE 9,90"])
        ->assertOk()->assertJsonPath('data.total', 9.9);
});

test('quick approval requires a registered device and an existing step', function (): void {
    [$token] = ($this->mobileLogin)();

    ($this->api)($token)->getJson('/api/v1/mobile/approvals')->assertOk();
    ($this->api)($token)->postJson('/api/v1/mobile/approvals/'.Str::uuid().'/decision', ['decision' => 'approved'])->assertNotFound();
    ($this->api)($token)->postJson('/api/v1/mobile/approvals/'.Str::uuid().'/decision', ['decision' => 'maybe'])->assertStatus(422);
});
