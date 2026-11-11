<?php

declare(strict_types=1);

use App\Actions\Auth\BootstrapSupportUser;
use App\Enums\Auth\AuthProblem;
use App\Mail\EmailAlreadyRegisteredMail;
use App\Mail\TenantAccessDecisionMail;
use App\Mail\VerifyEmailMail;
use App\Models\Tenant;
use App\Models\TenantAccessRequest;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TaxIdentifier;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

// These tests exercise the optional bearer-token mode, which is off by default: they turn it on and ask for it.
beforeEach(fn () => config(['auth.token_mode.enabled' => true]));

beforeEach(function (): void {
    Mail::fake();
});

/**
 * Generated at runtime: it satisfies the password policy and is the same for the whole test run.
 */
function registrationPassword(): string
{
    static $password = null;

    return $password ??= 'Aa1!'.Str::random(20);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Giulia',
        'last_name' => 'Bianchi',
        'email' => 'giuliabianchitest@example.com',
        'password' => registrationPassword(),
        'password_confirmation' => registrationPassword(),
        'company_name' => 'Bianchi Srl',
        'country' => 'IT',
        'tax_id' => '12345678901',
        'terms_accepted' => true,
        'privacy_accepted' => true,
    ], $overrides);
}

function lastVerificationToken(): string
{
    $mail = Mail::queued(VerifyEmailMail::class)->last();
    parse_str((string) parse_url($mail->link, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

function registerAndVerify(array $overrides = []): User
{
    test()->postJson('/api/v1/auth/register', registrationPayload($overrides))->assertStatus(202);
    test()->getJson('/api/v1/auth/verify-email?token='.urlencode(lastVerificationToken()))->assertOk();

    return User::where('email', $overrides['email'] ?? 'giuliabianchitest@example.com')->firstOrFail();
}

function platformAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();

    return $admin;
}

function loginAs(string $email = 'giuliabianchitest@example.com', ?string $password = null)
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password ?? registrationPassword(), 'token_mode' => true]);
}

function ledgerActions(): array
{
    return AuditLedgerEntry::pluck('action')->all();
}

test('registering creates an unverified account with a pending request and no tenant', function (): void {
    $response = $this->postJson('/api/v1/auth/register', registrationPayload());

    $response->assertStatus(202);

    $user = User::where('email', 'giuliabianchitest@example.com')->firstOrFail();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->name)->toBe('Giulia Bianchi')
        ->and($user->first_name)->toBe('Giulia')
        ->and($user->last_name)->toBe('Bianchi')
        ->and($user->terms_accepted_at)->not->toBeNull()
        ->and($user->privacy_accepted_at)->not->toBeNull()
        ->and($user->is_platform_admin)->toBeFalse()
        ->and(Hash::check(registrationPassword(), $user->password))->toBeTrue()
        ->and($user->tenants)->toBeEmpty()
        ->and($user->tenantAccessRequest->status)->toBe(TenantAccessRequest::PENDING)
        ->and($user->tenantAccessRequest->tenant_id)->toBeNull()
        ->and(Tenant::count())->toBe(0);

    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo('giuliabianchitest@example.com'));
    expect(ledgerActions())->toContain(RegistrationAudit::USER_REGISTERED, RegistrationAudit::VERIFICATION_SENT);
});

test('the raw token is never stored and verification emails are queued and encrypted', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    $token = lastVerificationToken();
    $user = User::where('email', 'giuliabianchitest@example.com')->firstOrFail();

    expect($user->email_verification_token)->toBe(hash('sha256', $token))
        ->and($user->toArray())->not->toHaveKey('email_verification_token')
        ->and(new VerifyEmailMail($user, 'https://x', now()))->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and(json_encode(AuditLedgerEntry::all()->toArray()))->not->toContain($token)->not->toContain('giuliabianchitest@example.com');
});

test('the answer does not reveal whether the email address is already registered', function (): void {
    $fresh = $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'new@example.com']));

    $existing = User::factory()->create(['email' => 'taken@example.com', 'password' => registrationPassword().'original']);
    $taken = $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'taken@example.com']));

    expect($taken->status())->toBe($fresh->status())
        ->and($taken->json())->toBe($fresh->json());

    Mail::assertQueued(EmailAlreadyRegisteredMail::class, fn (EmailAlreadyRegisteredMail $mail): bool => $mail->hasTo('taken@example.com'));
    Mail::assertNotQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo('taken@example.com'));

    expect(Hash::check(registrationPassword().'original', $existing->refresh()->password))->toBeTrue()
        ->and(TenantAccessRequest::where('user_id', $existing->id)->exists())->toBeFalse();
});

test('a soft deleted account is not revived by a new registration', function (): void {
    $deleted = User::factory()->create(['email' => 'gone@example.com']);
    $deleted->delete();

    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'gone@example.com']))->assertStatus(202);

    expect($deleted->refresh()->trashed())->toBeTrue();
    Mail::assertNotQueued(VerifyEmailMail::class);
});

test('registering again on an unverified address replaces the data and invalidates the first link', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['password' => registrationPassword().'first', 'password_confirmation' => registrationPassword().'first']))->assertStatus(202);
    $firstToken = lastVerificationToken();

    $this->postJson('/api/v1/auth/register', registrationPayload(['first_name' => 'Owner', 'company_name' => 'Owner Srl']))->assertStatus(202);

    $user = User::where('email', 'giuliabianchitest@example.com')->sole();

    expect(Hash::check(registrationPassword(), $user->password))->toBeTrue()
        ->and($user->first_name)->toBe('Owner')
        ->and($user->tenantAccessRequest->requested_company_name)->toBe('Owner Srl');

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($firstToken))
        ->assertStatus(400)->assertJsonPath('type', AuthProblem::VerificationTokenInvalid->value);
    $this->getJson('/api/v1/auth/verify-email?token='.urlencode(lastVerificationToken()))->assertOk();
});

test('registration validates the input', function (array|Closure $overrides, string $field): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(value($overrides)))
        ->assertStatus(422)->assertJsonValidationErrors($field);

    expect(User::count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'first name' => [['first_name' => ''], 'first_name'],
    'last name' => [['last_name' => ''], 'last_name'],
    'email format' => [['email' => 'not-an-email'], 'email'],
    'weak password' => [['password' => 'weak', 'password_confirmation' => 'weak'], 'password'],
    'password mismatch' => [fn (): array => ['password_confirmation' => registrationPassword().'x'], 'password'],
    'company' => [['company_name' => ''], 'company_name'],
    'country' => [['country' => 'FR'], 'country'],
    'italian vat format' => [['tax_id' => '123'], 'tax_id'],
    'swiss uid on italy' => [['tax_id' => 'CHE-123.456.789'], 'tax_id'],
    'terms' => [['terms_accepted' => false], 'terms_accepted'],
    'privacy' => [['privacy_accepted' => false], 'privacy_accepted'],
]);

test('the password complexity is configurable', function (): void {
    config(['auth.registration.password' => ['min_length' => 4, 'mixed_case' => false, 'numbers' => false, 'symbols' => false]]);

    $this->postJson('/api/v1/auth/register', registrationPayload(['password' => 'easy', 'password_confirmation' => 'easy']))->assertStatus(202);
});

test('tax identifiers are compared in canonical form', function (string $stored, string $typed, string $country): void {
    $tenant = Tenant::create(['name' => 'Known', 'legal_name' => 'Known', 'country' => $country, 'default_currency' => 'EUR', 'vat_number' => $stored]);

    expect(TaxIdentifier::findTenant((string) TaxIdentifier::normalize($typed, $country))?->id)->toBe($tenant->id);
})->with([
    'plain italian' => ['12345678901', '12345678901', 'IT'],
    'italian prefix stored' => ['IT12345678901', '12345678901', 'IT'],
    'italian prefix typed' => ['12345678901', 'IT 12345678901', 'IT'],
    'swiss with suffix' => ['CHE-123.456.789 MWST', 'che123456789', 'CH'],
]);

test('a similar tax identifier does not match another company', function (): void {
    Tenant::create(['name' => 'Known', 'legal_name' => 'Known', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '12345678901']);

    expect(TaxIdentifier::findTenant('12345678902'))->toBeNull()
        ->and(TaxIdentifier::findTenant('123456789'))->toBeNull();
});

test('the answer does not reveal whether the company is known', function (): void {
    Tenant::create(['name' => 'Known', 'legal_name' => 'Known', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '12345678901']);

    $known = $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'a@example.com']));
    $unknown = $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'b@example.com', 'tax_id' => '98765432109']));

    expect($known->json())->toBe($unknown->json());
});

test('login is blocked until the email is confirmed and then until the tenant is approved', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    loginAs()->assertForbidden()->assertJsonPath('type', AuthProblem::EmailNotVerified->value);
    expect(User::where('email', 'giuliabianchitest@example.com')->first()->tokens()->count())->toBe(0);

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode(lastVerificationToken()))->assertOk();

    $user = User::where('email', 'giuliabianchitest@example.com')->first();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->email_verification_token)->toBeNull()
        ->and(ledgerActions())->toContain(RegistrationAudit::EMAIL_VERIFIED);

    loginAs()->assertForbidden()->assertJsonPath('type', AuthProblem::TenantApprovalPending->value);
    expect($user->tokens()->count())->toBe(0);
});

test('a wrong password never reveals the state of the account', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    loginAs(password: registrationPassword().'wrong')->assertUnauthorized();
});

test('a token cannot be used twice', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
    $token = lastVerificationToken();

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($token))->assertOk();
    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($token))
        ->assertStatus(400)->assertJsonPath('type', AuthProblem::VerificationTokenInvalid->value);

    expect(ledgerActions())->toContain(RegistrationAudit::TOKEN_INVALID);
});

test('expired tokens are rejected', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
    $token = lastVerificationToken();

    $this->travel(config('auth.registration.verification_expires_minutes') + 1)->minutes();

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($token))
        ->assertStatus(410)->assertJsonPath('type', AuthProblem::VerificationTokenExpired->value);

    expect(User::where('email', 'giuliabianchitest@example.com')->first()->email_verified_at)->toBeNull()
        ->and(ledgerActions())->toContain(RegistrationAudit::TOKEN_EXPIRED);
});

test('the verification link lifetime is configurable', function (): void {
    config(['auth.registration.verification_expires_minutes' => 5]);
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
    $token = lastVerificationToken();

    $this->travel(6)->minutes();

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($token))->assertStatus(410);
});

test('forged or missing tokens are rejected', function (string $token): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($token))
        ->assertStatus(400)->assertJsonPath('type', AuthProblem::VerificationTokenInvalid->value);

    expect(User::where('email', 'giuliabianchitest@example.com')->first()->email_verified_at)->toBeNull();
})->with(['', 'garbage', 'abc.def', 'a.b.c']);

test('a new link can be requested and invalidates the previous one', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
    $first = lastVerificationToken();

    $this->postJson('/api/v1/auth/resend-verification-email', ['email' => 'GIULIABIANCHITEST@example.com '])->assertStatus(202);
    $second = lastVerificationToken();

    expect($second)->not->toBe($first)
        ->and(ledgerActions())->toContain(RegistrationAudit::VERIFICATION_RESENT);

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($first))->assertStatus(400);
    $this->getJson('/api/v1/auth/verify-email?token='.urlencode($second))->assertOk();
});

test('resending does not reveal whether an address is known or verified', function (): void {
    User::factory()->create(['email' => 'verified@example.com']);

    $unknown = $this->postJson('/api/v1/auth/resend-verification-email', ['email' => 'unknown@example.com']);
    $verified = $this->postJson('/api/v1/auth/resend-verification-email', ['email' => 'verified@example.com']);

    $unknown->assertStatus(202);
    expect($verified->json())->toBe($unknown->json());
    Mail::assertNothingQueued();
});

test('registration and resending are rate limited', function (): void {
    foreach (range(1, config('auth.registration.rate_limits.register_per_minute')) as $i) {
        $this->postJson('/api/v1/auth/register', registrationPayload(['email' => "user{$i}@example.com"]))->assertStatus(202);
    }
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'one-more@example.com']))->assertStatus(429);

    foreach (range(1, config('auth.registration.rate_limits.resend_per_minute')) as $i) {
        $this->postJson('/api/v1/auth/resend-verification-email', ['email' => "user{$i}@example.com"])->assertStatus(202);
    }
    $this->postJson('/api/v1/auth/resend-verification-email', ['email' => 'user1@example.com'])->assertStatus(429);
});

test('errors are localized', function (string $locale, string $expected): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    $this->withHeader('X-Language-Code', $locale)
        ->getJson('/api/v1/auth/verify-email?token=garbage')
        ->assertStatus(400)->assertJsonPath('title', $expected);
})->with([
    ['it', 'Link non valido'],
    ['en', 'Invalid link'],
    ['de', 'Ungültiger Link'],
    ['fr', 'Lien non valide'],
]);

test('every template renders in the four languages', function (string $locale, string $expected): void {
    $user = User::factory()->create(['first_name' => 'Giulia', 'locale' => $locale]);

    $verify = (new VerifyEmailMail($user, 'https://app.test/verify-email?token=abc', now()->addDay()))->locale($locale)->render();
    expect($verify)->toContain('Giulia')->toContain('https://app.test/verify-email?token=abc')->toContain($expected);

    expect((new EmailAlreadyRegisteredMail($user))->locale($locale)->render())->toContain('Giulia')
        ->and((new TenantAccessDecisionMail($user, TenantAccessRequest::APPROVED))->locale($locale)->render())->toContain('/login')
        ->and((new TenantAccessDecisionMail($user, TenantAccessRequest::REJECTED))->locale($locale)->render())->toContain('Giulia');
})->with([
    ['it', 'Conferma indirizzo email'],
    ['en', 'Confirm email address'],
    ['de', 'E-Mail-Adresse bestätigen'],
    ['fr', 'Confirmer l'],
]);

test('the emails use the language of the registration', function (): void {
    $this->withHeader('X-Language-Code', 'de')->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    expect(User::where('email', 'giuliabianchitest@example.com')->first()->locale)->toBe('de');
    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->locale === 'de');
});

test('only platform administrators reach the access requests', function (): void {
    $this->getJson('/api/v1/admin/tenant-access-requests')->assertUnauthorized();

    $tenant = Tenant::create(['name' => 'T', 'legal_name' => 'T', 'country' => 'IT', 'default_currency' => 'EUR']);
    $owner = User::factory()->create();
    $owner->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/admin/tenant-access-requests')->assertForbidden();
    $this->postJson('/api/v1/admin/tenant-access-requests/'.Str::uuid().'/approve')->assertForbidden();
});

test('administrators see the verified pending requests only', function (): void {
    registerAndVerify();
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'unverified@example.com']))->assertStatus(202);

    Sanctum::actingAs(platformAdmin());

    $this->getJson('/api/v1/admin/tenant-access-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.email', 'giuliabianchitest@example.com')
        ->assertJsonPath('data.0.requested_tax_id', '12345678901')
        ->assertJsonPath('meta.total', 1);
});

test('approving a new company creates the tenant with the user as owner and opens the application', function (): void {
    $user = registerAndVerify();
    Sanctum::actingAs(platformAdmin());

    $this->postJson("/api/v1/admin/tenant-access-requests/{$user->tenantAccessRequest->id}/approve")
        ->assertOk()->assertJsonPath('data.status', TenantAccessRequest::APPROVED);

    $tenant = Tenant::where('vat_number', '12345678901')->sole();

    expect($tenant->name)->toBe('Bianchi Srl')
        ->and($tenant->country)->toBe('IT')
        ->and($user->refresh()->getRoleInTenant($tenant->id))->toBe('owner');

    Mail::assertQueued(TenantAccessDecisionMail::class, fn (TenantAccessDecisionMail $mail): bool => $mail->hasTo('giuliabianchitest@example.com') && $mail->status === TenantAccessRequest::APPROVED);

    $this->app['auth']->forgetGuards();
    $token = loginAs()->assertOk()->assertJsonPath('data.active_tenant.id', $tenant->id)->json('data.token');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/profile')->assertOk()->assertJsonPath('data.active_tenant.id', $tenant->id);
    $this->withToken($token)->getJson('/api/v1/sales/invoices')->assertOk();
    $this->withToken($token)->getJson('/api/v1/crm/contacts')->assertOk();
});

test('a swiss company becomes a tenant in CHF with its UID', function (): void {
    $user = registerAndVerify(['country' => 'CH', 'tax_id' => 'CHE-123.456.789']);
    Sanctum::actingAs(platformAdmin());

    $this->postJson("/api/v1/admin/tenant-access-requests/{$user->tenantAccessRequest->id}/approve")->assertOk();

    $tenant = $user->refresh()->tenants()->sole();
    expect($tenant->default_currency)->toBe('CHF')
        ->and($tenant->country)->toBe('CH')
        ->and($tenant->uid_number)->toBe('CHE-123.456.789');
});

test('approving a matched company adds the user as a member without creating a tenant', function (): void {
    $tenant = Tenant::create(['name' => 'Known', 'legal_name' => 'Known', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => 'IT12345678901']);
    $user = registerAndVerify();

    expect($user->tenantAccessRequest->tenant_id)->toBe($tenant->id)
        ->and($user->belongsToTenant($tenant->id))->toBeFalse();

    Sanctum::actingAs(platformAdmin());
    $this->postJson("/api/v1/admin/tenant-access-requests/{$user->tenantAccessRequest->id}/approve")->assertOk();

    expect(Tenant::count())->toBe(1)
        ->and($user->refresh()->getRoleInTenant($tenant->id))->toBe('member');
});

test('choosing an existing company gives no access to its data before the approval', function (): void {
    $tenant = Tenant::create(['name' => 'Known', 'legal_name' => 'Known', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => '12345678901']);
    $user = registerAndVerify();

    Sanctum::actingAs($user);

    $this->withHeader('X-Tenant-Id', $tenant->id)->getJson('/api/v1/sales/invoices')->assertForbidden();
    $this->flushHeaders();
    $this->getJson('/api/v1/sales/invoices')->assertStatus(400);
});

test('an administrator can move the user to another tenant and choose the role', function (): void {
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = registerAndVerify();
    Sanctum::actingAs(platformAdmin());

    $this->postJson("/api/v1/admin/tenant-access-requests/{$user->tenantAccessRequest->id}/move", ['tenant_id' => (string) Str::uuid()])->assertStatus(422);

    $this->postJson("/api/v1/admin/tenant-access-requests/{$user->tenantAccessRequest->id}/move", ['tenant_id' => $other->id, 'role' => 'accountant'])
        ->assertOk()->assertJsonPath('data.tenant.id', $other->id);

    expect(Tenant::count())->toBe(1)
        ->and($user->refresh()->getRoleInTenant($other->id))->toBe('accountant')
        ->and(AuditLedgerEntry::where('tenant_id', $other->id)->where('action', RegistrationAudit::ACCESS_MOVED)->count())->toBe(1);
});

test('a rejected user cannot sign in and no tenant is created', function (): void {
    $user = registerAndVerify();
    Sanctum::actingAs(platformAdmin());

    $this->postJson("/api/v1/admin/tenant-access-requests/{$user->tenantAccessRequest->id}/reject", ['reason' => 'Unknown company'])
        ->assertOk()->assertJsonPath('data.status', TenantAccessRequest::REJECTED);

    expect(Tenant::count())->toBe(0);
    Mail::assertQueued(TenantAccessDecisionMail::class, fn (TenantAccessDecisionMail $mail): bool => $mail->status === TenantAccessRequest::REJECTED);

    $this->app['auth']->forgetGuards();
    loginAs()->assertForbidden()->assertJsonPath('type', AuthProblem::TenantAccessRejected->value);
});

test('requests can be decided only once and only after the email is confirmed', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
    $request = TenantAccessRequest::sole();

    Sanctum::actingAs(platformAdmin());
    $this->postJson("/api/v1/admin/tenant-access-requests/{$request->id}/approve")->assertStatus(409);

    $this->getJson('/api/v1/auth/verify-email?token='.urlencode(lastVerificationToken()))->assertOk();
    $this->postJson("/api/v1/admin/tenant-access-requests/{$request->id}/approve")->assertOk();
    $this->postJson("/api/v1/admin/tenant-access-requests/{$request->id}/approve")->assertStatus(409);
    $this->postJson("/api/v1/admin/tenant-access-requests/{$request->id}/reject")->assertStatus(409);
});

test('accounts that never confirmed the address are pruned, the others are kept', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'stale@example.com']))->assertStatus(202);
    $this->travel(config('auth.registration.prune_unverified_after_days') + 1)->days();

    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'recent@example.com']))->assertStatus(202);
    $seeded = User::factory()->unverified()->create(['email' => 'seeded@example.com', 'created_at' => now()->subYear()]);

    $this->artisan('auth:prune-unverified-users')->assertSuccessful();

    expect(User::where('email', 'stale@example.com')->exists())->toBeFalse()
        ->and(User::where('email', 'recent@example.com')->exists())->toBeTrue()
        ->and(User::whereKey($seeded->id)->exists())->toBeTrue()
        ->and(TenantAccessRequest::count())->toBe(1);
});

test('the platform administrator role is granted and revoked from the command line', function (): void {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('auth:grant-platform-admin', ['email' => 'ops@example.com'])->assertSuccessful();
    expect($user->refresh()->is_platform_admin)->toBeTrue();

    $this->artisan('auth:grant-platform-admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_platform_admin)->toBeFalse();

    $this->artisan('auth:grant-platform-admin', ['email' => 'nobody@example.com'])->assertFailed();
});

test('the role cannot be obtained through registration', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['is_platform_admin' => true, 'email_verified_at' => now()->toIso8601String()]))->assertStatus(202);

    $user = User::where('email', 'giuliabianchitest@example.com')->first();

    expect($user->is_platform_admin)->toBeFalse()->and($user->email_verified_at)->toBeNull();
});

test('registering the same address is rate limited across IP addresses', function (): void {
    $limit = config('auth.registration.rate_limits.register_per_email_per_hour');

    foreach (range(1, $limit) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
    }

    $token = lastVerificationToken();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->postJson('/api/v1/auth/register', registrationPayload(['email' => ' GIULIABIANCHITEST@example.com ']))->assertStatus(429);

    expect(lastVerificationToken())->toBe($token);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
        ->postJson('/api/v1/auth/register', registrationPayload(['email' => 'someone-else@example.com']))->assertStatus(202);
});

test('a malformed email does not break the rate limiter', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => ['not', 'a', 'string']]))->assertStatus(422);
    $this->postJson('/api/v1/auth/resend-verification-email', ['email' => ['not', 'a', 'string']])->assertStatus(422);
});

test('the status filter of the access requests is validated', function (?string $status, int $expected): void {
    Sanctum::actingAs(platformAdmin());

    $this->getJson('/api/v1/admin/tenant-access-requests'.($status === null ? '' : "?status={$status}"))->assertStatus($expected);
})->with([
    'default' => [null, 200],
    'pending' => ['pending', 200],
    'approved' => ['approved', 200],
    'rejected' => ['rejected', 200],
    'unknown' => ['deleted', 422],
]);

test('a decision that is rolled back sends no email and changes nothing', function (): void {
    $user = registerAndVerify();
    $request = $user->tenantAccessRequest;
    Sanctum::actingAs(platformAdmin());

    $this->mock(AuditLedgerInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('recordPayload')->andThrow(new RuntimeException('Ledger unavailable')));

    $this->postJson("/api/v1/admin/tenant-access-requests/{$request->id}/approve")->assertServerError();

    expect($request->refresh()->status)->toBe(TenantAccessRequest::PENDING)
        ->and(Tenant::count())->toBe(0)
        ->and($user->refresh()->tenants)->toBeEmpty();
    Mail::assertNotQueued(TenantAccessDecisionMail::class);
});

test('the migration marks the existing accounts as verified and keeps the known dates', function (): void {
    $legacy = User::factory()->unverified()->create(['created_at' => '2025-01-15 10:00:00']);
    $verified = User::factory()->create(['created_at' => '2025-01-15 10:00:00', 'email_verified_at' => '2025-02-20 09:00:00']);

    $migration = require database_path('migrations/2027_03_03_000000_add_self_registration_to_users_table.php');
    $migration->down();
    $migration->up();

    expect($legacy->refresh()->email_verified_at?->toDateTimeString())->toBe('2025-01-15 10:00:00')
        ->and($verified->refresh()->email_verified_at?->toDateTimeString())->toBe('2025-02-20 09:00:00');
});

test('the bootstrapped support user does not get the platform administrator role', function (): void {
    $result = app(BootstrapSupportUser::class)->execute();

    expect($result->user->refresh()->is_platform_admin)->toBeFalse();
});
