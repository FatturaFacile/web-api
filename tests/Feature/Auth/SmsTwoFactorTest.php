<?php

declare(strict_types=1);

use App\Contracts\Sms\SmsDeliveryException;
use App\Contracts\Sms\SmsGateway;
use App\Enums\Auth\AuthProblem;
use App\Models\TrustedDevice;
use App\Services\Auth\OtpThrottledException;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\SecondFactorCodes;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Sms\HttpSmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\PhoneNumber;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MfaScenario;

uses(RefreshDatabase::class);

const MFA_PHONE = '+41791234567';

beforeEach(function (): void {
    config(['auth.token_mode.enabled' => true]);
    Mail::fake();

    $this->sms = new class implements SmsGateway
    {
        /** @var list<array{to: string, message: string}> */
        public array $sent = [];

        public bool $fail = false;

        public function send(string $to, string $message): void
        {
            if ($this->fail) {
                throw new SmsDeliveryException('down');
            }

            $this->sent[] = ['to' => $to, 'message' => $message];
        }
    };
    app()->instance(SmsGateway::class, $this->sms);

    $this->tenant = MfaScenario::tenant();
    $this->user = MfaScenario::member($this->tenant);
    $this->headers = ['X-Tenant-Id' => $this->tenant->id];
});

/** The code of the last SMS the fake gateway received. */
function mfaSmsCode(object $t): string
{
    expect($t->sms->sent)->not->toBeEmpty();
    preg_match('/\b(\d{6})\b/', (string) end($t->sms->sent)['message'], $matches);

    return (string) ($matches[1] ?? '');
}

/** Enrols the SMS factor of a user through the API and returns the recovery codes created with it. */
function mfaEnableSms(object $t, $user): array
{
    Sanctum::actingAs($user);

    $t->withHeaders($t->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => MfaScenario::PASSWORD, 'phone' => MFA_PHONE])->assertOk();

    return $t->withHeaders($t->headers)->postJson('/api/v1/auth/two-factor/sms/confirm', ['code' => mfaSmsCode($t)])->assertOk()->json('data.recovery_codes');
}

test('the SMS factor is enrolled with the password, an E.164 number and the received code', function (): void {
    Sanctum::actingAs($this->user);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => 'wrong', 'phone' => MFA_PHONE])->assertUnprocessable();
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => MfaScenario::PASSWORD, 'phone' => '0791234567'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => MfaScenario::PASSWORD])->assertUnprocessable();
    expect($this->sms->sent)->toBe([]);

    $sent = $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => MfaScenario::PASSWORD, 'phone' => '+41 79 123-45.67'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+41*******67')
        ->assertJsonPath('data.expires_in', 600);

    expect($sent->json('data.resend_available_in'))->toBeGreaterThan(0)
        ->and($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['to'])->toBe(MFA_PHONE)
        ->and($this->sms->sent[0]['message'])->toContain(mfaSmsCode($this))
        // The number is not stored before the code is confirmed.
        ->and($this->user->fresh()->two_factor_sms_phone)->toBeNull();

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/confirm', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('type', AuthProblem::TwoFactorCodeInvalid->value);

    $codes = $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/confirm', ['code' => mfaSmsCode($this)])
        ->assertOk()
        ->assertJsonPath('data.methods', ['sms', 'recovery_code'])
        ->json('data.recovery_codes');

    $user = $this->user->fresh();
    expect($codes)->toHaveCount(8)
        ->and($user->two_factor_sms_phone)->toBe(MFA_PHONE)
        ->and($user->two_factor_sms_confirmed_at)->not->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('two_factor_sms_phone'))->not->toBe(MFA_PHONE)
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_SMS_ENABLED)->count())->toBe(1);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => MfaScenario::PASSWORD, 'phone' => MFA_PHONE])->assertStatus(409);
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/confirm', ['code' => '123456'])->assertStatus(409);

    $this->withHeaders($this->headers)->getJson('/api/v1/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.sms_enabled', true)
        ->assertJsonPath('data.sms_phone', '+41*******67')
        ->assertJsonPath('data.methods', ['sms', 'recovery_code'])
        ->assertJsonMissingPath('data.two_factor_sms_phone');
});

test('the SMS code is rendered in the language of the user and never the plain number in the profile', function (): void {
    Sanctum::actingAs($this->user);
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => MfaScenario::PASSWORD, 'phone' => MFA_PHONE])->assertOk();

    expect($this->sms->sent[0]['message'])->toContain('codice di verifica')->and($this->sms->sent[0]['message'])->toContain(config('app.name'));
});

test('a user with only the SMS factor gets the code at login and signs in with it', function (): void {
    mfaEnableSms($this, $this->user);
    $this->travel(2)->minutes();

    $login = MfaScenario::login($this->user)->assertOk();

    expect($login->json('data.methods'))->toBe(['sms', 'recovery_code'])
        ->and($login->json('data.default_method'))->toBe('sms')
        ->and($login->json('data.sms_code_sent'))->toBeTrue()
        ->and($login->json('data.email_code_sent'))->toBeFalse()
        ->and($this->sms->sent)->toHaveCount(2);

    $code = mfaSmsCode($this);
    $token = $login->json('data.challenge_token');

    MfaScenario::answer($token, ['sms_code' => $code === '000000' ? '111111' : '000000'])->assertUnauthorized();
    $answer = MfaScenario::answer($token, ['sms_code' => $code])->assertOk();

    expect($answer->json('data.token'))->toBeString()
        ->and($answer->json('data.user.two_factor_methods'))->toBe(['sms', 'recovery_code']);

    $passed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_PASSED)->sole();
    expect($passed->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => 'sms']);

    // The code is spent.
    MfaScenario::answer(MfaScenario::challengeToken($this->user), ['sms_code' => $code])->assertUnauthorized();
});

test('the challenge sends the SMS code on request, with the same limits as the email code', function (): void {
    $totp = MfaScenario::enableTotp($this->user, $this->tenant);
    mfaEnableSms($this, $this->user);
    $this->travel(2)->minutes();

    $login = MfaScenario::login($this->user)->assertOk();
    expect($login->json('data.methods'))->toBe(['totp', 'sms', 'recovery_code'])
        ->and($login->json('data.sms_code_sent'))->toBeFalse();
    $token = $login->json('data.challenge_token');

    $this->postJson('/api/v1/auth/two-factor/challenge/sms', ['challenge_token' => $token])->assertOk()->assertJsonPath('data.expires_in', 600);
    $this->postJson('/api/v1/auth/two-factor/challenge/sms', ['challenge_token' => $token])
        ->assertStatus(429)
        ->assertJsonPath('type', AuthProblem::TwoFactorEmailThrottled->value)
        ->assertHeader('Retry-After');

    MfaScenario::answer($token, ['sms_code' => mfaSmsCode($this)])->assertOk();

    MfaScenario::answer(MfaScenario::challengeToken($this->user), ['code' => MfaScenario::totpCode($totp['secret'])])->assertOk();
});

test('the SMS challenge needs a live challenge and a user with the SMS factor', function (): void {
    $this->postJson('/api/v1/auth/two-factor/challenge/sms', ['challenge_token' => str_repeat('a', 64)])
        ->assertUnauthorized()
        ->assertJsonPath('type', AuthProblem::TwoFactorChallengeInvalid->value);

    MfaScenario::enableTotp($this->user, $this->tenant);
    $token = MfaScenario::challengeToken($this->user);

    $this->postJson('/api/v1/auth/two-factor/challenge/sms', ['challenge_token' => $token])
        ->assertStatus(409)
        ->assertJsonPath('type', AuthProblem::TwoFactorMethodUnavailable->value);
    MfaScenario::answer($token, ['sms_code' => '123456'])->assertUnauthorized();

    $failed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED)->sole();
    expect($failed->payload_after)->toMatchArray(['mfa_method' => 'sms', 'reason' => 'method_unavailable'])
        ->and($this->sms->sent)->toBe([]);
});

test('a gateway failure answers 503 and sends nothing', function (): void {
    $this->sms->fail = true;
    Sanctum::actingAs($this->user);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/enable', ['current_password' => MfaScenario::PASSWORD, 'phone' => MFA_PHONE])
        ->assertStatus(503)
        ->assertJsonPath('type', AuthProblem::SmsDeliveryFailed->value);
});

test('SMS codes are limited to one a minute and five an hour, apart from the email counter', function (): void {
    $codes = app(SecondFactorCodes::class);
    $this->user->forceFill(['two_factor_sms_phone' => MFA_PHONE, 'two_factor_sms_confirmed_at' => now()])->save();

    $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_SMS);

    expect(fn () => $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_SMS))->toThrow(OtpThrottledException::class);

    // The email counter is independent.
    $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_EMAIL);

    for ($sent = 1; $sent < 5; $sent++) {
        $this->travel(61)->seconds();
        $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_SMS);
    }

    $this->travel(61)->seconds();
    expect(fn () => $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_SMS))->toThrow(OtpThrottledException::class)
        ->and($this->sms->sent)->toHaveCount(5);
});

test('an SMS code is single use, expires, dies after five wrong tries and is never valid for another user or channel', function (): void {
    $codes = app(SecondFactorCodes::class);
    $other = MfaScenario::member($this->tenant);
    $this->user->forceFill(['two_factor_sms_phone' => MFA_PHONE, 'two_factor_sms_confirmed_at' => now()])->save();

    $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_SMS);
    $code = mfaSmsCode($this);
    $wrong = $code === '000000' ? '111111' : '000000';

    expect($codes->verify($other, SecondFactorCodes::PURPOSE_VERIFY, $code, SecondFactorCodes::CHANNEL_SMS))->toBeFalse()
        ->and($codes->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code, SecondFactorCodes::CHANNEL_EMAIL))->toBeFalse()
        ->and($codes->verify($this->user, SecondFactorCodes::PURPOSE_ENROL, $code, SecondFactorCodes::CHANNEL_SMS))->toBeFalse()
        ->and($codes->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code, SecondFactorCodes::CHANNEL_SMS))->toBeTrue()
        ->and($codes->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $code, SecondFactorCodes::CHANNEL_SMS))->toBeFalse();

    $this->travel(61)->seconds();
    $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_SMS);
    $fresh = mfaSmsCode($this);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect($codes->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $fresh === $wrong ? '222222' : $wrong, SecondFactorCodes::CHANNEL_SMS))->toBeFalse();
    }

    expect($codes->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $fresh, SecondFactorCodes::CHANNEL_SMS))->toBeFalse();

    $this->travel(61)->seconds();
    $codes->send($this->user, SecondFactorCodes::PURPOSE_VERIFY, SecondFactorCodes::CHANNEL_SMS);
    $expiring = mfaSmsCode($this);
    $this->travel(11)->minutes();

    expect($codes->verify($this->user, SecondFactorCodes::PURPOSE_VERIFY, $expiring, SecondFactorCodes::CHANNEL_SMS))->toBeFalse();
});

test('the SMS factor is removed with the password, and the trusted devices and the number go with it', function (): void {
    mfaEnableSms($this, $this->user);
    TrustedDevice::query()->create(['user_id' => $this->user->id, 'token_hash' => hash('sha256', 'z'), 'expires_at' => now()->addDay()]);

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/sms', ['current_password' => 'wrong'])->assertUnprocessable();
    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/sms', ['current_password' => MfaScenario::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.methods', []);

    $user = $this->user->fresh();
    expect($user->two_factor_sms_phone)->toBeNull()
        ->and($user->two_factor_sms_confirmed_at)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and(TrustedDevice::query()->count())->toBe(0)
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_SMS_DISABLED)->count())->toBe(1);

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/sms', ['current_password' => MfaScenario::PASSWORD])->assertStatus(409);
});

test('removing the SMS factor keeps the authenticator app, and a mandatory role keeps its last factor', function (): void {
    MfaScenario::enableTotp($this->user, $this->tenant);
    mfaEnableSms($this, $this->user);

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/sms', ['current_password' => MfaScenario::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.methods', ['totp', 'recovery_code']);

    config(['iam.mandatory_mfa' => true]);
    $owner = MfaScenario::member($this->tenant, null, 'owner');
    mfaEnableSms($this, $owner);
    $this->travel(2)->minutes();

    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/sms', ['current_password' => MfaScenario::PASSWORD])
        ->assertForbidden()
        ->assertJsonPath('code', 'mfa_cannot_be_disabled');
    expect($owner->fresh()->two_factor_sms_confirmed_at)->not->toBeNull();
});

test('an SMS-only user changes the password with a code sent for the purpose', function (): void {
    mfaEnableSms($this, $this->user);
    $this->travel(2)->minutes();

    $payload = ['current_password' => MfaScenario::PASSWORD, 'password' => 'Zz9!Another-Long-Pass-456', 'password_confirmation' => 'Zz9!Another-Long-Pass-456'];

    $this->withHeaders($this->headers)->putJson('/api/v1/auth/password', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/send')->assertOk();
    $this->withHeaders($this->headers)->putJson('/api/v1/auth/password', [...$payload, 'sms_code' => mfaSmsCode($this)])->assertOk();
});

test('the SMS send endpoint needs the SMS factor', function (): void {
    Sanctum::actingAs($this->user);

    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/sms/send')->assertStatus(409);
});

test('resetting the factors removes the SMS factor', function (): void {
    mfaEnableSms($this, $this->user);

    app(TwoFactorAuthentication::class)->reset($this->user->fresh());

    $user = $this->user->fresh();
    expect($user->two_factor_sms_phone)->toBeNull()
        ->and(app(TwoFactorAuthentication::class)->isEnabled($user))->toBeFalse();
});

test('phone numbers are normalised, validated and masked', function (): void {
    expect(PhoneNumber::normalize(' +41 (79) 123-45.67 '))->toBe(MFA_PHONE)
        ->and(PhoneNumber::isValid(MFA_PHONE))->toBeTrue()
        ->and(PhoneNumber::isValid('0791234567'))->toBeFalse()
        ->and(PhoneNumber::isValid('+0123456789'))->toBeFalse()
        ->and(PhoneNumber::isValid('+4179'))->toBeFalse()
        ->and(PhoneNumber::mask(MFA_PHONE))->toBe('+41*******67')
        ->and(PhoneNumber::mask('+123'))->toBe('***');
});

test('the log gateway sends nothing out and the http gateway posts the message with the token', function (): void {
    app(LogSmsGateway::class)->send(MFA_PHONE, 'hello');

    config(['sms.http.url' => 'https://sms.example.test/send', 'sms.http.token' => 'secret-token', 'sms.http.sender' => 'Acme']);
    Http::fake(['sms.example.test/*' => Http::response(['ok' => true])]);

    app(HttpSmsGateway::class)->send(MFA_PHONE, 'Your code');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sms.example.test/send'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['to'] === MFA_PHONE
        && $request['message'] === 'Your code'
        && $request['sender'] === 'Acme');

    Http::fake(['sms.example.test/*' => Http::response([], 500)]);
    expect(fn () => app(HttpSmsGateway::class)->send(MFA_PHONE, 'Your code'))->toThrow(SmsDeliveryException::class);

    config(['sms.http.url' => '']);
    expect(fn () => app(HttpSmsGateway::class)->send(MFA_PHONE, 'Your code'))->toThrow(SmsDeliveryException::class);
});
