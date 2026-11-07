<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Visitors\Jobs\PurgeExpiredVisitorsJob;
use Modules\Visitors\Mail\VisitorArrivedMail;
use Modules\Visitors\Models\Visitor;
use Modules\Visitors\Models\VisitorKiosk;
use Modules\Visitors\Models\VisitorVisit;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo('2027-05-12 08:00:00');
    $this->tenant = Tenant::create(['name' => 'Officine Rossi', 'legal_name' => 'Officine Rossi Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->receptionist = visitorsMemberOf($this->tenant, 'receptionist');
    $this->host = visitorsMemberOf($this->tenant, 'member');
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  list<string>|null  $permissions
 */
function visitorsMemberOf(Tenant $tenant, string $role, ?array $permissions = null): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => $permissions === null ? null : json_encode($permissions)]);

    return $user;
}

function visitorsActAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);
    test()->flushHeaders();

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * Registers a kiosk as an administrator and returns the headers it authenticates with.
 *
 * @return array<string, string>
 */
function visitorsKioskHeaders(Tenant $tenant): array
{
    visitorsActAs(visitorsMemberOf($tenant, 'admin'), $tenant);
    $kiosk = test()->postJson('/api/v1/visitors/kiosks', ['name' => 'Reception ingresso'])->assertCreated()->json('data');
    test()->flushHeaders();
    app(TenantContext::class)->clear();

    return ['X-Kiosk-Key' => $kiosk['device_key'], 'X-Kiosk-Secret' => $kiosk['secret'], 'Accept' => 'application/json'];
}

test('a host announces a guest, reception checks them in with a badge, the host gets a mail and the guest checks out', function (): void {
    visitorsActAs($this->host, $this->tenant);
    $visit = $this->postJson('/api/v1/visitors/visits', [
        'visitor' => ['first_name' => 'Giulia', 'last_name' => 'Bianchi', 'company_name' => 'Fornitore Spa', 'email' => 'giulia@fornitore.it'],
        'host_user_id' => $this->host->id,
        'expected_at' => '2027-05-12 10:00:00',
        'purpose' => 'Offerta ricambi',
    ])->assertCreated()->assertJsonPath('data.status', 'expected')->json('data');

    visitorsActAs($this->receptionist, $this->tenant);
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/check-in", ['badge_number' => 'V-001'])
        ->assertOk()
        ->assertJsonPath('data.status', 'checked_in')
        ->assertJsonPath('data.badge_number', 'V-001')
        ->assertJsonPath('data.visitor.full_name', 'Giulia Bianchi');

    Mail::assertQueued(VisitorArrivedMail::class, fn (VisitorArrivedMail $mail): bool => $mail->hasTo($this->host->email)
        && $mail->visitorName === 'Giulia Bianchi' && $mail->badgeNumber === 'V-001' && $mail->arrivalTime === '10:00');
    expect(VisitorVisit::query()->findOrFail($visit['id'])->host_notified_at)->not->toBeNull();

    $this->getJson('/api/v1/visitors/on-site')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.visitor.last_name', 'Bianchi');
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/check-out")->assertOk()->assertJsonPath('data.status', 'checked_out');
    $this->getJson('/api/v1/visitors/on-site')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/check-out")->assertUnprocessable();
});

test('personal data are encrypted at rest and the identity document never leaves the API', function (): void {
    visitorsActAs($this->receptionist, $this->tenant);
    $visitor = $this->postJson('/api/v1/visitors/visitors', ['first_name' => 'Marco', 'last_name' => 'Verdi', 'phone' => '+39 02 555 0101', 'document_number' => 'CA12345AB'])
        ->assertCreated()
        ->assertJsonPath('data.has_document', true)
        ->assertJsonMissingPath('data.document_number')
        ->json('data');

    $row = (array) DB::table('visitors')->where('id', $visitor['id'])->first();

    expect($row['first_name'])->not->toContain('Marco')
        ->and($row['last_name'])->not->toContain('Verdi')
        ->and($row['phone'])->not->toContain('555')
        ->and($row['document_number'])->not->toContain('CA12345AB')
        ->and(Visitor::query()->findOrFail($visitor['id'])->document_number)->toBe('CA12345AB');
});

test('a badge is worn by one visitor at a time', function (): void {
    visitorsActAs($this->receptionist, $this->tenant);
    $first = $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'Anna', 'last_name' => 'Neri'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 09:00:00'])->json('data.id');
    $second = $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'Luca', 'last_name' => 'Gialli'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 09:30:00'])->json('data.id');

    $this->postJson("/api/v1/visitors/visits/{$first}/check-in", ['badge_number' => 'V-007'])->assertOk();
    $this->postJson("/api/v1/visitors/visits/{$second}/check-in", ['badge_number' => 'V-007'])->assertUnprocessable()->assertJsonValidationErrors('badge_number');

    $this->postJson("/api/v1/visitors/visits/{$first}/check-out")->assertOk();
    $this->postJson("/api/v1/visitors/visits/{$second}/check-in", ['badge_number' => 'V-007'])->assertOk();
});

test('ordinary members see and announce only their own guests; reception and kiosks are reserved', function (): void {
    $colleague = visitorsMemberOf($this->tenant, 'member');
    visitorsActAs($this->receptionist, $this->tenant);
    $visit = $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'Paolo', 'last_name' => 'Blu'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 11:00:00'])->assertCreated()->json('data');

    visitorsActAs($colleague, $this->tenant);
    $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'X', 'last_name' => 'Y'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 11:00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('host_user_id');
    $this->getJson("/api/v1/visitors/visits/{$visit['id']}")->assertForbidden();
    $this->getJson("/api/v1/visitors/visitors/{$visit['visitor_id']}")->assertForbidden();
    $this->getJson('/api/v1/visitors/visits')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/visitors/on-site')->assertForbidden();
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/check-in", ['badge_number' => 'V-1'])->assertForbidden();
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/cancel")->assertForbidden();
    $this->postJson('/api/v1/visitors/visitors', ['first_name' => 'A', 'last_name' => 'B'])->assertForbidden();

    visitorsActAs($this->host, $this->tenant);
    $this->getJson('/api/v1/visitors/visits')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/visitors/visitors/{$visit['visitor_id']}")->assertOk();
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    visitorsActAs($this->receptionist, $this->tenant);
    $this->postJson('/api/v1/visitors/kiosks', ['name' => 'Tablet'])->assertForbidden();

    visitorsActAs(visitorsMemberOf($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'A', 'last_name' => 'B'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 11:00:00'])->assertForbidden();

    visitorsActAs(visitorsMemberOf($this->tenant, 'member', ['visitors.manage']), $this->tenant);
    $this->getJson('/api/v1/visitors/on-site')->assertOk();
});

test('the host must belong to the company and visits of another company are invisible', function (): void {
    $other = Tenant::create(['name' => 'Altra', 'legal_name' => 'Altra Srl', 'country' => 'CH', 'default_currency' => 'CHF']);
    $stranger = visitorsMemberOf($other, 'admin');

    visitorsActAs($this->receptionist, $this->tenant);
    $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'A', 'last_name' => 'B'], 'host_user_id' => $stranger->id, 'expected_at' => '2027-05-12 11:00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('host_user_id');
    $visit = $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'A', 'last_name' => 'B'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 11:00:00'])->json('data');

    visitorsActAs($stranger, $other);
    $this->getJson("/api/v1/visitors/visits/{$visit['id']}")->assertNotFound();
    $this->postJson('/api/v1/visitors/visits', ['visitor_id' => $visit['visitor_id'], 'host_user_id' => $stranger->id, 'expected_at' => '2027-05-12 11:00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('visitor_id');
});

test('the kiosk authenticates with its hashed secret, shows today by first name and checks people in and out', function (): void {
    $headers = visitorsKioskHeaders($this->tenant);
    $kiosk = VisitorKiosk::query()->withoutGlobalScopes()->sole();
    expect($kiosk->secret_hash)->toBe(hash('sha256', $headers['X-Kiosk-Secret']))->not->toBe($headers['X-Kiosk-Secret']);

    visitorsActAs($this->host, $this->tenant);
    $visit = $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'Sara', 'last_name' => 'Colombo', 'email' => 'sara@cliente.it'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 09:00:00'])->json('data');
    $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'Domani', 'last_name' => 'Rossi'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-13 09:00:00'])->assertCreated();
    $this->flushHeaders();
    app(TenantContext::class)->clear();

    $this->getJson('/api/v1/visitor-kiosk/expected', ['X-Kiosk-Key' => $headers['X-Kiosk-Key'], 'X-Kiosk-Secret' => 'wrong'])->assertUnauthorized();
    $expected = $this->getJson('/api/v1/visitor-kiosk/expected', $headers)->assertOk()->assertJsonCount(1, 'data');
    expect($expected->json('data.0'))->toMatchArray(['visitor_name' => 'Sara C.', 'host_name' => $this->host->name])
        ->and($expected->json('data.0'))->not->toHaveKeys(['email', 'company_name', 'purpose']);

    $this->postJson('/api/v1/visitor-kiosk/check-in', ['visit_id' => $visit['id'], 'badge_number' => 'K-01'], $headers)->assertCreated()->assertJsonPath('data.status', 'checked_in');
    expect(VisitorVisit::query()->withoutGlobalScopes()->findOrFail($visit['id'])->kiosk_id)->toBe($kiosk->id);
    Mail::assertQueued(VisitorArrivedMail::class);

    $this->postJson('/api/v1/visitor-kiosk/check-in', [
        'visitor' => ['first_name' => 'Walk', 'last_name' => 'In'], 'host_user_id' => $this->host->id, 'badge_number' => 'K-02',
    ], $headers)->assertCreated()->assertJsonPath('data.visitor_name', 'Walk I.');

    $this->postJson('/api/v1/visitor-kiosk/check-out', ['badge_number' => 'K-01'], $headers)->assertOk()->assertJsonPath('data.status', 'checked_out');
    $this->postJson('/api/v1/visitor-kiosk/check-out', ['badge_number' => 'K-01'], $headers)->assertUnprocessable();
});

test('a kiosk cannot reach the visits of another company, nor work once retired or rotated', function (): void {
    $other = Tenant::create(['name' => 'Altra', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $headers = visitorsKioskHeaders($other);

    visitorsActAs($this->receptionist, $this->tenant);
    $visit = $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'A', 'last_name' => 'B'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 09:00:00'])->json('data');
    $this->flushHeaders();
    app(TenantContext::class)->clear();

    $this->getJson('/api/v1/visitor-kiosk/expected', $headers)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/visitor-kiosk/check-in', ['visit_id' => $visit['id'], 'badge_number' => 'K-1'], $headers)->assertUnprocessable()->assertJsonValidationErrors('visit_id');
    $this->postJson('/api/v1/visitor-kiosk/check-in', ['visitor' => ['first_name' => 'A', 'last_name' => 'B'], 'host_user_id' => $this->host->id, 'badge_number' => 'K-1'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('host_user_id');

    $admin = visitorsMemberOf($other, 'admin');
    $kiosk = VisitorKiosk::query()->withoutGlobalScopes()->sole();
    visitorsActAs($admin, $other);
    $rotated = $this->postJson("/api/v1/visitors/kiosks/{$kiosk->id}/rotate-secret")->assertOk()->json('data.secret');
    $this->flushHeaders();
    app(TenantContext::class)->clear();
    $this->getJson('/api/v1/visitor-kiosk/expected', $headers)->assertUnauthorized();
    $this->getJson('/api/v1/visitor-kiosk/expected', [...$headers, 'X-Kiosk-Secret' => $rotated])->assertOk();

    visitorsActAs($admin, $other);
    $this->deleteJson("/api/v1/visitors/kiosks/{$kiosk->id}")->assertNoContent();
    $this->flushHeaders();
    app(TenantContext::class)->clear();
    $this->getJson('/api/v1/visitor-kiosk/expected', [...$headers, 'X-Kiosk-Secret' => $rotated])->assertUnauthorized();
});

test('during a restore the kiosk still reads but refuses check-ins', function (): void {
    $headers = visitorsKioskHeaders($this->tenant);
    $this->tenant->forceFill(['is_maintenance' => true])->save();

    $this->getJson('/api/v1/visitor-kiosk/expected', $headers)->assertOk();
    $this->postJson('/api/v1/visitor-kiosk/check-out', ['badge_number' => 'K-1'], $headers)->assertStatus(503)->assertHeader('Retry-After', '60');
});

test('visitor data are erased at the end of the configurable retention, never while on site or expected', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $old = VisitorVisit::factory()->checkedOut()->create(['tenant_id' => $this->tenant->id, 'checked_out_at' => now()->subDays(40)]);
    Visitor::query()->whereKey($old->visitor_id)->update(['last_visit_at' => now()->subDays(40)]);
    $recent = VisitorVisit::factory()->checkedOut()->create(['tenant_id' => $this->tenant->id, 'checked_out_at' => now()->subDays(10)]);
    $onSite = VisitorVisit::factory()->checkedIn('V-9')->create(['tenant_id' => $this->tenant->id, 'checked_in_at' => now()->subDays(60)]);
    $noShow = VisitorVisit::factory()->create(['tenant_id' => $this->tenant->id, 'expected_at' => now()->subDays(45)]);
    app(TenantContext::class)->clear();

    visitorsActAs(visitorsMemberOf($this->tenant, 'admin'), $this->tenant);
    $this->putJson('/api/v1/settings/preferences/visitors', ['retention_days' => 0])->assertUnprocessable();
    $this->putJson('/api/v1/settings/preferences/visitors', ['retention_days' => 30])->assertOk();
    app(TenantContext::class)->clear();

    (new PurgeExpiredVisitorsJob($this->tenant->id))->handle(app(TenantContext::class));

    $remaining = VisitorVisit::query()->withoutGlobalScopes()->pluck('id')->all();
    expect($remaining)->toContain($recent->id, $onSite->id)
        ->not->toContain($old->id, $noShow->id)
        ->and(Visitor::query()->withoutGlobalScopes()->whereKey($old->visitor_id)->exists())->toBeFalse()
        ->and(Visitor::query()->withoutGlobalScopes()->whereKey($recent->visitor_id)->exists())->toBeTrue();
});

test('a visitor can be erased on request, but not while on site', function (): void {
    visitorsActAs($this->receptionist, $this->tenant);
    $visit = $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'A', 'last_name' => 'B'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 09:00:00'])->json('data');
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/check-in", ['badge_number' => 'V-3'])->assertOk();

    $this->deleteJson("/api/v1/visitors/visitors/{$visit['visitor_id']}")->assertUnprocessable();
    $this->postJson("/api/v1/visitors/visits/{$visit['id']}/check-out")->assertOk();
    $this->deleteJson("/api/v1/visitors/visitors/{$visit['visitor_id']}")->assertNoContent();

    expect(VisitorVisit::query()->withoutGlobalScopes()->whereKey($visit['id'])->exists())->toBeFalse();
});

test('the visits of a day follow the company time zone', function (): void {
    visitorsActAs($this->receptionist, $this->tenant);
    $this->postJson('/api/v1/visitors/visits', ['visitor' => ['first_name' => 'Notte', 'last_name' => 'Fonda'], 'host_user_id' => $this->host->id, 'expected_at' => '2027-05-12 22:30:00'])->assertCreated();

    $this->getJson('/api/v1/visitors/visits?date=2027-05-13')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/visitors/visits?date=2027-05-12')->assertOk()->assertJsonCount(0, 'data');
});

test('every model of the module has a factory', function (string $model): void {
    expect($model::factory()->create()->exists)->toBeTrue();
})->with([Visitor::class, VisitorVisit::class, VisitorKiosk::class]);
