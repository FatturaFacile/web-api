<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\TwoFactorEmailCodeMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Helpers of the advanced second-factor tests: members with a known password, enrolment of the authenticator app and
 * of the email code through the API, the login and challenge calls, and the email code read from the faked mailer.
 * The caller turns the bearer-token mode on and fakes the mailer.
 */
final class MfaScenario
{
    public const PASSWORD = 'Bb2!Strong-Pass-123';

    public static function tenant(string $name = 'Officina Verdi'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'legal_name' => $name.' SRL',
            'fiscal_code' => (string) random_int(10_000_000_000, 99_999_999_999),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]);
    }

    public static function member(Tenant $tenant, ?string $email = null, string $role = 'member'): User
    {
        $user = User::create([
            'name' => 'Utente Prova',
            'email' => $email ?? Str::uuid().'@example.com',
            'password' => Hash::make(self::PASSWORD),
            'locale' => 'it',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    }

    /**
     * @return array{secret: string, recovery_codes: list<string>}
     */
    public static function enableTotp(User $user, Tenant $tenant): array
    {
        Sanctum::actingAs($user);
        $headers = ['X-Tenant-Id' => $tenant->id];

        $secret = test()->withHeaders($headers)
            ->postJson('/api/v1/auth/two-factor/enable', ['current_password' => self::PASSWORD])
            ->assertOk()
            ->json('data.secret');

        $codes = test()->withHeaders($headers)
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Totp::class)->codeAt($secret, time())])
            ->assertOk()
            ->json('data.recovery_codes');

        return ['secret' => $secret, 'recovery_codes' => $codes];
    }

    /**
     * Turns the email factor on through the API and returns the recovery codes created with it (empty when the user
     * already had some).
     *
     * @return list<string>
     */
    public static function enableEmail(User $user, Tenant $tenant): array
    {
        Sanctum::actingAs($user);
        $headers = ['X-Tenant-Id' => $tenant->id];

        test()->withHeaders($headers)->postJson('/api/v1/auth/two-factor/email/enable', ['current_password' => self::PASSWORD])->assertOk();

        return test()->withHeaders($headers)
            ->postJson('/api/v1/auth/two-factor/email/confirm', ['code' => self::lastEmailCode($user)])
            ->assertOk()
            ->json('data.recovery_codes');
    }

    /**
     * The code of the last email sent to the user, read from the faked mailer.
     */
    public static function lastEmailCode(User $user): string
    {
        $mails = Mail::queued(TwoFactorEmailCodeMail::class, static fn (TwoFactorEmailCodeMail $mail): bool => $mail->user->is($user));
        expect($mails)->not->toBeEmpty();

        return (string) $mails->last()->code;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function login(User $user, array $extra = []): TestResponse
    {
        return test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'token_mode' => true, ...$extra]);
    }

    public static function challengeToken(User $user): string
    {
        $response = self::login($user)->assertOk();

        expect($response->json('data.two_factor_required'))->toBeTrue();

        return (string) $response->json('data.challenge_token');
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    public static function answer(string $challengeToken, array $answer): TestResponse
    {
        return test()->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challengeToken, 'token_mode' => true, ...$answer]);
    }

    public static function totpCode(string $secret, int $offsetSeconds = 30): string
    {
        return app(Totp::class)->codeAt($secret, time() + $offsetSeconds);
    }
}
