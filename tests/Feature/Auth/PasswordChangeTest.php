<?php

declare(strict_types=1);

use App\Enums\Auth\AuthProblem;
use App\Mail\PasswordChangedMail;
use App\Models\PasswordResetToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\PasswordResetTokens;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactorAuthentication;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    config(['session.driver' => 'database']);

    $this->password = passwordChangeRandomPassword();
    $this->newPassword = passwordChangeRandomPassword();
    $this->tenant = Tenant::create([
        'name' => 'Studio Rossi',
        'legal_name' => 'Studio Rossi SRL',
        'fiscal_code' => '12345678901',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    $this->user = User::create([
        'name' => 'Marco Rossi',
        'first_name' => 'Marco',
        'email' => 'marco.rossi@example.com',
        'password' => Hash::make($this->password),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
});

/**
 * Generated at runtime, so no password is written in the test: symbols, digits and both cases satisfy the policy.
 */
function passwordChangeRandomPassword(): string
{
    return Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
}

function passwordChangeLogin(string $password, string $userAgent = 'FatturaFacile Mobile/1.0'): string
{
    return (string) test()->withHeader('User-Agent', $userAgent)
        ->postJson('/api/v1/auth/login', ['email' => 'marco.rossi@example.com', 'password' => $password])
        ->assertOk()
        ->json('data.token');
}

/**
 * @param  array<string, mixed>  $payload
 */
function submitPasswordChange(string $token, array $payload)
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-Id' => test()->tenant->id])
        ->putJson('/api/v1/auth/password', $payload);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function passwordChangePayload(array $extra = []): array
{
    return [
        'current_password' => test()->password,
        'password' => test()->newPassword,
        'password_confirmation' => test()->newPassword,
        ...$extra,
    ];
}

/**
 * @return list<string>
 */
function passwordChangeLedgerActions(): array
{
    return AuditLedgerEntry::pluck('action')->all();
}

/**
 * Turns two factors on through the API with the given token and returns the secret and the recovery codes.
 *
 * @return array{secret: string, recovery_codes: list<string>}
 */
function passwordChangeTwoFactor(string $token): array
{
    $headers = ['Authorization' => "Bearer {$token}", 'X-Tenant-Id' => test()->tenant->id];
    $secret = (string) test()->withHeaders($headers)
        ->postJson('/api/v1/auth/two-factor/enable', ['current_password' => test()->password])
        ->assertOk()
        ->json('data.secret');
    $codes = test()->withHeaders($headers)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Totp::class)->codeAt($secret, time())])
        ->assertOk()
        ->json('data.recovery_codes');

    return ['secret' => $secret, 'recovery_codes' => $codes];
}

test('a guest cannot change a password', function (): void {
    $this->putJson('/api/v1/auth/password', passwordChangePayload())->assertUnauthorized();
});

test('a wrong current password is rejected on its field and counted', function (): void {
    $token = passwordChangeLogin($this->password);

    submitPasswordChange($token, passwordChangePayload(['current_password' => $this->password.'x']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password' => __('password_change.errors.current_password_invalid')]);

    expect(Hash::check($this->password, $this->user->fresh()->password))->toBeTrue()
        ->and(passwordChangeLedgerActions())->toContain(RegistrationAudit::PASSWORD_CHANGE_FAILED)
        ->and(passwordChangeLedgerActions())->not->toContain(RegistrationAudit::PASSWORD_CHANGED);
    Mail::assertNothingQueued();
});

test('the new password follows the policy and matches its confirmation', function (string $case): void {
    $token = passwordChangeLogin($this->password);
    $override = match ($case) {
        'too weak' => ['password' => $weak = Str::lower(Str::random(5)), 'password_confirmation' => $weak],
        'confirmation differs' => ['password_confirmation' => $this->newPassword.'x'],
    };

    submitPasswordChange($token, passwordChangePayload($override))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect(Hash::check($this->password, $this->user->fresh()->password))->toBeTrue()
        ->and(passwordChangeLedgerActions())->not->toContain(RegistrationAudit::PASSWORD_CHANGE_FAILED);
})->with(['too weak', 'confirmation differs']);

test('the same password is rejected with its own message', function (): void {
    $token = passwordChangeLogin($this->password);

    submitPasswordChange($token, passwordChangePayload(['password' => $this->password, 'password_confirmation' => $this->password]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => __('password_change.errors.same_password')]);
});

test('a change revokes every token and session, the current one included', function (): void {
    $current = passwordChangeLogin($this->password, 'Laptop');
    $other = passwordChangeLogin($this->password, 'Phone');
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);

    submitPasswordChange($current, passwordChangePayload())
        ->assertOk()
        ->assertJsonPath('message', __('password_change.messages.changed'));

    expect($this->user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->user->id)->count())->toBe(0)
        ->and($this->user->fresh()->password_changed_at)->not->toBeNull();

    foreach ([$current, $other] as $token) {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-Id' => $this->tenant->id])
            ->getJson('/api/v1/auth/profile')
            ->assertUnauthorized();
    }

    $changed = AuditLedgerEntry::query()->where('action', RegistrationAudit::PASSWORD_CHANGED)->sole();
    expect($changed->payload_after)->toMatchArray(['bearer_clients' => 2, 'browser_sessions' => 1])
        ->and(passwordChangeLedgerActions())->toContain(RegistrationAudit::PASSWORD_CHANGE_NOTIFIED);
});

test('only the new password signs in after the change', function (): void {
    submitPasswordChange(passwordChangeLogin($this->password), passwordChangePayload())->assertOk();
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/auth/login', ['email' => 'marco.rossi@example.com', 'password' => $this->password])->assertUnauthorized();
    passwordChangeLogin($this->newPassword);
});

test('the SPA cookie session of the request is closed as well', function (): void {
    $spa = ['Origin' => 'https://localhost:4200', 'Referer' => 'https://localhost:4200/settings/profile'];
    $this->withHeaders($spa)->get('/sanctum/csrf-cookie');
    $this->withHeaders($spa)
        ->postJson('/api/v1/auth/login', ['email' => 'marco.rossi@example.com', 'password' => $this->password, 'tenant_id' => $this->tenant->id])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie');

    $this->withHeaders([...$spa, 'X-Tenant-Id' => $this->tenant->id])
        ->putJson('/api/v1/auth/password', passwordChangePayload())
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeaders([...$spa, 'X-Tenant-Id' => $this->tenant->id])->getJson('/api/v1/auth/profile')->assertUnauthorized();
});

test('the user is notified with date, address and device, and told to reset the password if it was not them', function (): void {
    $token = passwordChangeLogin($this->password);

    $this->app['auth']->forgetGuards();
    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-Id' => $this->tenant->id,
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
    ])->putJson('/api/v1/auth/password', passwordChangePayload())->assertOk();

    Mail::assertQueued(PasswordChangedMail::class, fn (PasswordChangedMail $mail): bool => $mail->hasTo('marco.rossi@example.com')
        && ! $mail->afterRecovery
        && $mail->device === 'Chrome · macOS'
        && $mail->ipAddress !== null
        && $mail->locale === 'it');

    $mail = Mail::queued(PasswordChangedMail::class)->sole();
    app()->setLocale('it');
    expect($mail->render())->toContain(e(__('password_reset.mail.changed.security_profile')))
        ->not->toContain(e(__('password_reset.mail.changed.security')));
});

test('a pending reset link stops working after a change', function (): void {
    $link = app(PasswordResetTokens::class)->issue($this->user);

    submitPasswordChange(passwordChangeLogin($this->password), passwordChangePayload())->assertOk();

    expect(PasswordResetToken::query()->where('user_id', $this->user->id)->whereNull('used_at')->count())->toBe(0);
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $link['token'],
        'password' => $this->newPassword.'x',
        'password_confirmation' => $this->newPassword.'x',
    ])->assertStatus(400)->assertJsonPath('type', AuthProblem::PasswordResetTokenInvalid->value);
});

test('repeated wrong answers lock the change for a while', function (): void {
    $token = passwordChangeLogin($this->password);
    $max = (int) config('auth.password_change.max_failed_attempts');

    for ($attempt = 0; $attempt < $max; $attempt++) {
        submitPasswordChange($token, passwordChangePayload(['current_password' => $this->password.$attempt]))->assertUnprocessable();
    }

    submitPasswordChange($token, passwordChangePayload())
        ->assertStatus(429)
        ->assertJsonPath('type', AuthProblem::PasswordChangeLocked->value);

    expect(Hash::check($this->password, $this->user->fresh()->password))->toBeTrue()
        ->and(passwordChangeLedgerActions())->toContain(RegistrationAudit::PASSWORD_CHANGE_ATTEMPTS_EXCEEDED);

    $this->travel((int) config('auth.password_change.lockout_minutes') + 1)->minutes();

    submitPasswordChange($token, passwordChangePayload())->assertOk();
});

test('the lock of one user does not affect another', function (): void {
    $other = User::create([
        'name' => 'Anna Neri',
        'email' => 'anna.neri@example.com',
        'password' => Hash::make($this->password),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $other->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $token = passwordChangeLogin($this->password);

    for ($attempt = 0; $attempt < (int) config('auth.password_change.max_failed_attempts'); $attempt++) {
        submitPasswordChange($token, passwordChangePayload(['current_password' => $this->password.$attempt]))->assertUnprocessable();
    }

    $this->app['auth']->forgetGuards();
    $otherToken = (string) $this->postJson('/api/v1/auth/login', ['email' => 'anna.neri@example.com', 'password' => $this->password])->json('data.token');

    submitPasswordChange($otherToken, passwordChangePayload())->assertOk();
});

test('with two factors the change also needs a code of the app', function (): void {
    $token = passwordChangeLogin($this->password);
    $secret = passwordChangeTwoFactor($token)['secret'];

    submitPasswordChange($token, passwordChangePayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => __('password_change.errors.code_required')]);

    submitPasswordChange($token, passwordChangePayload(['code' => '000000']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(Hash::check($this->password, $this->user->fresh()->password))->toBeTrue()
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::PASSWORD_CHANGE_FAILED)->count())->toBe(1);

    // The next step of the app: the code of the confirmation cannot be used twice.
    submitPasswordChange($token, passwordChangePayload(['code' => app(Totp::class)->codeAt($secret, time() + 30)]))->assertOk();

    expect(Hash::check($this->newPassword, $this->user->fresh()->password))->toBeTrue();
});

test('with two factors a recovery code works in place of the code and is used up', function (): void {
    $token = passwordChangeLogin($this->password);
    $recoveryCode = passwordChangeTwoFactor($token)['recovery_codes'][0];

    submitPasswordChange($token, passwordChangePayload(['recovery_code' => $recoveryCode]))->assertOk();

    expect(passwordChangeLedgerActions())->toContain(RegistrationAudit::TWO_FACTOR_RECOVERY_CODE_USED)
        ->and($this->user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    expect(app(TwoFactorAuthentication::class)->remainingRecoveryCodes($this->user->fresh()))
        ->toBe((int) config('auth.two_factor.recovery_codes') - 1);
});

test('the ledger never receives the email address', function (): void {
    $token = passwordChangeLogin($this->password);
    submitPasswordChange($token, passwordChangePayload(['current_password' => $this->password.'x']))->assertUnprocessable();
    submitPasswordChange($token, passwordChangePayload())->assertOk();

    $entries = AuditLedgerEntry::query()
        ->whereIn('action', [RegistrationAudit::PASSWORD_CHANGED, RegistrationAudit::PASSWORD_CHANGE_FAILED, RegistrationAudit::PASSWORD_CHANGE_NOTIFIED])
        ->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->map(fn ($entry): string => (string) json_encode($entry->getAttributes()))->implode(' '))->not->toContain('marco.rossi@example.com');
});
