<?php

declare(strict_types=1);

use App\Enums\Auth\AuthProblem;
use App\Mail\TwoFactorEmailCodeMail;
use App\Services\Auth\OtpThrottledException;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\SecondFactorCodes;
use App\Services\Auth\TwoFactorAuthentication;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MfaScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['auth.token_mode.enabled' => true]);
    Mail::fake();

    $this->tenant = MfaScenario::tenant();
    $this->user = MfaScenario::member($this->tenant);
    $this->headers = ['X-Tenant-Id' => $this->tenant->id];
});

test('the email factor is enrolled with the password and the emailed code, and returns recovery codes once', function (): void {
    Sanctum::actingAs($this->user);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/enable', ['current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');
    Mail::assertNothingQueued();

    $sent = $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/enable', ['current_password' => MfaScenario::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.expires_in', 600);
    expect($sent->json('data.resend_available_in'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    Mail::assertQueued(TwoFactorEmailCodeMail::class, 1);

    $code = MfaScenario::lastEmailCode($this->user);

    expect($code)->toMatch('/^\d{6}$/');

    $stored = Cache::get('auth:two-factor-code:email:enrol:'.$this->user->id);
    expect($stored['hash'])->not->toBe($code)
        ->and(json_encode($stored))->not->toContain($code);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/confirm', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('type', AuthProblem::TwoFactorCodeInvalid->value);

    $codes = $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/confirm', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.methods', [TwoFactorAuthentication::METHOD_EMAIL, TwoFactorAuthentication::METHOD_RECOVERY_CODE])
        ->json('data.recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($this->user->fresh()->two_factor_email_confirmed_at)->not->toBeNull()
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_EMAIL_ENABLED)->count())->toBe(1);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/confirm', ['code' => $code])->assertStatus(409);
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/enable', ['current_password' => MfaScenario::PASSWORD])->assertStatus(409);

    $this->withHeaders($this->headers)->getJson('/api/v1/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.email_enabled', true)
        ->assertJsonPath('data.totp_enabled', false)
        ->assertJsonPath('data.methods', ['email', 'recovery_code']);
});

test('a code is only valid for the confirmed enrolment code, not for another purpose', function (): void {
    $otp = app(SecondFactorCodes::class);
    $otp->send($this->user, SecondFactorCodes::PURPOSE_ENROL);
    $code = MfaScenario::lastEmailCode($this->user);

    expect($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code))->toBeFalse()
        ->and($otp->verify($this->user, SecondFactorCodes::PURPOSE_ENROL, $code))->toBeTrue();
});

test('a user with only the email factor gets the code at login and signs in with it', function (): void {
    MfaScenario::enableEmail($this->user, $this->tenant);
    $this->travel(2)->minutes();

    $login = MfaScenario::login($this->user)->assertOk();

    expect($login->json('data.two_factor_required'))->toBeTrue()
        ->and($login->json('data.methods'))->toBe(['email', 'recovery_code'])
        ->and($login->json('data.default_method'))->toBe('email')
        ->and($login->json('data.email_code_sent'))->toBeTrue()
        ->and($login->json('data.token'))->toBeNull();
    Mail::assertQueued(TwoFactorEmailCodeMail::class, 2);

    $answer = MfaScenario::answer($login->json('data.challenge_token'), ['email_code' => MfaScenario::lastEmailCode($this->user)])->assertOk();

    expect($answer->json('data.token'))->toBeString()
        ->and($answer->json('data.user.two_factor_methods'))->toBe(['email', 'recovery_code']);

    $passed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_PASSED)->sole();
    expect($passed->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => 'email']);
});

test('a user with both factors can answer with either and the login lists both methods', function (): void {
    $totp = MfaScenario::enableTotp($this->user, $this->tenant);
    $recovery = MfaScenario::enableEmail($this->user, $this->tenant);

    // The user already had recovery codes: none is created again.
    expect($recovery)->toBe([]);
    $this->travel(2)->minutes();

    $login = MfaScenario::login($this->user)->assertOk();

    expect($login->json('data.methods'))->toBe(['totp', 'email', 'recovery_code'])
        ->and($login->json('data.default_method'))->toBe('totp')
        ->and($login->json('data.email_code_sent'))->toBeFalse();

    $token = $login->json('data.challenge_token');
    $this->travel(2)->minutes();
    $this->postJson('/api/v1/auth/two-factor/challenge/email', ['challenge_token' => $token])->assertOk()->assertJsonPath('data.expires_in', 600);
    MfaScenario::answer($token, ['email_code' => MfaScenario::lastEmailCode($this->user)])->assertOk();

    MfaScenario::answer(MfaScenario::challengeToken($this->user), ['code' => MfaScenario::totpCode($totp['secret'])])->assertOk();
});

test('the email code of the challenge cannot be sent for an unknown challenge or to a user without the factor', function (): void {
    $this->postJson('/api/v1/auth/two-factor/challenge/email', ['challenge_token' => str_repeat('a', 64)])
        ->assertUnauthorized()
        ->assertJsonPath('type', AuthProblem::TwoFactorChallengeInvalid->value);

    MfaScenario::enableTotp($this->user, $this->tenant);
    $token = MfaScenario::challengeToken($this->user);

    $this->postJson('/api/v1/auth/two-factor/challenge/email', ['challenge_token' => $token])
        ->assertStatus(409)
        ->assertJsonPath('type', AuthProblem::TwoFactorMethodUnavailable->value);
    Mail::assertNothingQueued();
});

test('an email code is refused when wrong, and dies after five wrong tries', function (): void {
    $otp = app(SecondFactorCodes::class);
    $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
    $code = MfaScenario::lastEmailCode($this->user);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code === '000000' ? '111111' : '000000'))->toBeFalse();
    }

    expect($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code))->toBeFalse();
});

test('a correct code after a few wrong tries still works, once', function (): void {
    $otp = app(SecondFactorCodes::class);
    $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
    $code = MfaScenario::lastEmailCode($this->user);

    expect($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code === '000000' ? '111111' : '000000'))->toBeFalse()
        ->and($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code))->toBeTrue()
        ->and($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code))->toBeFalse();
});

test('an email code expires after ten minutes', function (): void {
    $otp = app(SecondFactorCodes::class);
    $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
    $code = MfaScenario::lastEmailCode($this->user);

    $this->travel(11)->minutes();

    expect($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code))->toBeFalse();
});

test('the code of one user is never valid for another', function (): void {
    $other = MfaScenario::member($this->tenant);
    $otp = app(SecondFactorCodes::class);
    $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);

    expect($otp->verify($other, SecondFactorCodes::PURPOSE_VERIFY, MfaScenario::lastEmailCode($this->user)))->toBeFalse();
});

test('a new code replaces the pending one', function (): void {
    $otp = app(SecondFactorCodes::class);
    $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
    $first = MfaScenario::lastEmailCode($this->user);

    $this->travel(61)->seconds();
    $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
    $second = MfaScenario::lastEmailCode($this->user);

    expect($first === $second || ! $otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $first))->toBeTrue()
        ->and($otp->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $second))->toBeTrue();
});

test('sending is limited to one code a minute and five an hour per user', function (): void {
    $otp = app(SecondFactorCodes::class);
    $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);

    try {
        $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
        $this->fail('The second code in a minute must be throttled.');
    } catch (OtpThrottledException $e) {
        expect($e->retryAfterSeconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    }

    for ($sent = 1; $sent < 5; $sent++) {
        $this->travel(61)->seconds();
        $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
    }

    $this->travel(61)->seconds();

    try {
        $otp->send($this->user, SecondFactorCodes::PURPOSE_VERIFY);
        $this->fail('The sixth code in an hour must be throttled.');
    } catch (OtpThrottledException $e) {
        expect($e->retryAfterSeconds)->toBeGreaterThan(60);
    }

    Mail::assertQueued(TwoFactorEmailCodeMail::class, 5);
});

test('the resend endpoint of the challenge answers 429 with Retry-After inside the minute', function (): void {
    MfaScenario::enableEmail($this->user, $this->tenant);
    $this->travel(2)->minutes();
    $token = MfaScenario::challengeToken($this->user);

    $this->postJson('/api/v1/auth/two-factor/challenge/email', ['challenge_token' => $token])
        ->assertStatus(429)
        ->assertJsonPath('type', AuthProblem::TwoFactorEmailThrottled->value)
        ->assertHeader('Retry-After');

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/two-factor/challenge/email', ['challenge_token' => $token])->assertOk();
});

test('five wrong email codes end the challenge', function (): void {
    MfaScenario::enableEmail($this->user, $this->tenant);
    $this->travel(2)->minutes();
    $token = MfaScenario::challengeToken($this->user);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        MfaScenario::answer($token, ['email_code' => '000000'])->assertUnauthorized()->assertJsonPath('type', AuthProblem::TwoFactorCodeInvalid->value);
    }

    MfaScenario::answer($token, ['email_code' => MfaScenario::lastEmailCode($this->user)])
        ->assertUnauthorized()
        ->assertJsonPath('type', AuthProblem::TwoFactorChallengeInvalid->value);

    $failed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED)->get();
    expect($failed)->toHaveCount(5)
        ->and($failed->first()->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => 'email', 'reason' => 'invalid_code'])
        ->and($failed->last()->payload_after['challenge_exhausted'])->toBeTrue();
});

test('an email code is refused for a user who does not have the email factor', function (): void {
    $totp = MfaScenario::enableTotp($this->user, $this->tenant);
    $token = MfaScenario::challengeToken($this->user);

    MfaScenario::answer($token, ['email_code' => '123456'])->assertUnauthorized();

    $failed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED)->sole();
    expect($failed->payload_after)->toMatchArray(['mfa_method' => 'email', 'reason' => 'method_unavailable']);

    MfaScenario::answer($token, ['code' => MfaScenario::totpCode($totp['secret'])])->assertOk();
});

test('the email factor is removed with the password, and with it the recovery codes when it was the last factor', function (): void {
    MfaScenario::enableEmail($this->user, $this->tenant);

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/email', ['current_password' => 'wrong'])->assertUnprocessable();

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/email', ['current_password' => MfaScenario::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.methods', []);

    $user = $this->user->fresh();
    expect($user->two_factor_email_confirmed_at)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_EMAIL_DISABLED)->count())->toBe(1);

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/email', ['current_password' => MfaScenario::PASSWORD])->assertStatus(409);
    MfaScenario::login($this->user)->assertOk()->assertJsonPath('data.two_factor_required', null);
});

test('removing the email factor keeps the authenticator app and its recovery codes', function (): void {
    MfaScenario::enableTotp($this->user, $this->tenant);
    MfaScenario::enableEmail($this->user, $this->tenant);

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/email', ['current_password' => MfaScenario::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.methods', ['totp', 'recovery_code']);
});

test('an owner of a tenant that enforces two-factor cannot remove the last factor', function (): void {
    config(['iam.mandatory_mfa' => true]);
    $owner = MfaScenario::member($this->tenant, null, 'owner');
    MfaScenario::enableEmail($owner, $this->tenant);

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/email', ['current_password' => MfaScenario::PASSWORD])
        ->assertForbidden()
        ->assertJsonPath('code', 'mfa_cannot_be_disabled');
    expect($owner->fresh()->two_factor_email_confirmed_at)->not->toBeNull();
});

test('an email-only user changes the password with a code sent for the purpose', function (): void {
    MfaScenario::enableEmail($this->user, $this->tenant);
    $this->travel(2)->minutes();

    $payload = ['current_password' => MfaScenario::PASSWORD, 'password' => 'Zz9!Another-Long-Pass-456', 'password_confirmation' => 'Zz9!Another-Long-Pass-456'];

    $this->withHeaders($this->headers)->putJson('/api/v1/auth/password', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/send')->assertOk();

    $this->withHeaders($this->headers)->putJson('/api/v1/auth/password', [...$payload, 'email_code' => MfaScenario::lastEmailCode($this->user)])->assertOk();
});

test('the email send endpoint needs the email factor', function (): void {
    Sanctum::actingAs($this->user);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/email/send')->assertStatus(409);
});

test('resetting the factors removes the email factor and its pending code', function (): void {
    MfaScenario::enableEmail($this->user, $this->tenant);

    app(TwoFactorAuthentication::class)->reset($this->user->fresh());

    $user = $this->user->fresh();
    expect($user->two_factor_email_confirmed_at)->toBeNull()
        ->and(app(TwoFactorAuthentication::class)->isEnabled($user))->toBeFalse()
        ->and(app(TwoFactorAuthentication::class)->availableMethods($user))->toBe([]);
});

test('recovery codes are audited with the way the access was opened and the method', function (): void {
    $totp = MfaScenario::enableTotp($this->user, $this->tenant);
    $token = MfaScenario::challengeToken($this->user);

    MfaScenario::answer($token, ['recovery_code' => $totp['recovery_codes'][0]])->assertOk();

    $used = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_RECOVERY_CODE_USED)->sole();
    expect($used->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => 'recovery_code', 'remaining_recovery_codes' => 7]);

    // The same recovery code is spent.
    MfaScenario::answer(MfaScenario::challengeToken($this->user), ['recovery_code' => $totp['recovery_codes'][0]])->assertUnauthorized();
});

test('the recovery codes are regenerated with the password and audited', function (): void {
    $totp = MfaScenario::enableTotp($this->user, $this->tenant);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/recovery-codes', ['current_password' => 'wrong'])->assertUnprocessable();
    $new = $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/recovery-codes', ['current_password' => MfaScenario::PASSWORD])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($new)->toHaveCount(8)->not->toContain($totp['recovery_codes'][0])
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_RECOVERY_CODES_REGENERATED)->count())->toBe(1);
});
