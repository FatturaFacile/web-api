<?php

declare(strict_types=1);

use App\Enums\Auth\AuthProblem;
use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetAlertMail;
use App\Mail\PasswordResetMail;
use App\Models\PasswordResetToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\DeviceDescription;
use App\Services\Auth\RegistrationAudit;
use Carbon\CarbonInterface;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
});

/**
 * Generated at runtime: it satisfies the password policy and is the same for the whole test run.
 */
function newPassword(): string
{
    static $password = null;

    return $password ??= 'Bb2!'.Str::random(20);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function resetUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'giuliabianchitest@example.com',
        'first_name' => 'Giulia',
        'email_verified_at' => now(),
        'is_active' => true,
        'locale' => 'it',
    ], $attributes));
}

function requestReset(string $email = 'giuliabianchitest@example.com')
{
    return test()->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
}

function lastResetToken(): string
{
    $mail = Mail::queued(PasswordResetMail::class)->last();
    parse_str((string) parse_url($mail->link, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

function submitReset(string $token, ?string $password = null)
{
    return test()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'password' => $password ?? newPassword(),
        'password_confirmation' => $password ?? newPassword(),
    ]);
}

function resetLedgerActions(): array
{
    return AuditLedgerEntry::pluck('action')->all();
}

test('a known address receives a queued, encrypted link and only the hash of the token is stored', function (): void {
    $user = resetUser();

    requestReset()->assertStatus(202);

    Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo($user->email) && $mail->locale === 'it');
    expect(new ReflectionClass(PasswordResetMail::class))
        ->implementsInterface(ShouldQueue::class)->toBeTrue()
        ->implementsInterface(ShouldBeEncrypted::class)->toBeTrue();

    $token = lastResetToken();
    $row = PasswordResetToken::where('user_id', $user->id)->sole();

    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->token_hash)->not->toBe($token)
        ->and($row->created_ip)->not->toBeNull()
        ->and(resetLedgerActions())->toContain(RegistrationAudit::PASSWORD_RESET_REQUESTED, RegistrationAudit::PASSWORD_RESET_SENT);
});

test('every address gets the same answer and only eligible accounts get an email', function (Closure $setup): void {
    $reference = requestReset('nessuno@example.com')->assertStatus(202)->json('message');

    $setup();

    expect(requestReset()->assertStatus(202)->json('message'))->toBe($reference);
    Mail::assertNothingQueued();
    expect(PasswordResetToken::count())->toBe(0);
})->with([
    'unknown' => [fn () => null],
    'disabled' => [fn () => resetUser(['is_active' => false])],
    'unverified' => [fn () => resetUser(['email_verified_at' => null])],
    'soft deleted' => [fn () => resetUser()->delete()],
]);

test('the ledger never receives the email address', function (): void {
    resetUser();

    requestReset();
    requestReset('nessuno@example.com');

    $entries = AuditLedgerEntry::query()->get()->map(fn ($entry): string => json_encode($entry->getAttributes()))->implode(' ');

    expect($entries)->not->toContain('giuliabianchitest')->not->toContain('nessuno@example.com');
});

test('the tenant is stored only when the user has exactly one', function (int $tenantCount, bool $stored): void {
    $user = resetUser();
    $tenants = Tenant::factory()->count($tenantCount)->create();
    $tenants->each(fn (Tenant $tenant) => $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']));

    requestReset();

    expect(PasswordResetToken::where('user_id', $user->id)->sole()->tenant_id)->toBe($stored ? $tenants->first()->id : null);
})->with([
    'none' => [0, false],
    'one' => [1, true],
    'several' => [2, false],
]);

test('a new request invalidates the previous link', function (): void {
    resetUser();

    requestReset();
    $first = lastResetToken();
    test()->postJson('/api/v1/auth/resend-reset-password-email', ['email' => 'giuliabianchitest@example.com'])->assertStatus(202);
    $second = lastResetToken();

    expect($first)->not->toBe($second)
        ->and(resetLedgerActions())->toContain(RegistrationAudit::PASSWORD_RESET_RESENT);
    submitReset($first)->assertStatus(400);
    submitReset($second)->assertOk();
});

test('validating a token does not consume it', function (): void {
    resetUser();
    requestReset();
    $token = lastResetToken();

    test()->getJson('/api/v1/auth/validate-reset-token?token='.urlencode($token))->assertOk();
    test()->getJson('/api/v1/auth/validate-reset-token?token='.urlencode($token))->assertOk();

    expect(resetLedgerActions())->toContain(RegistrationAudit::PASSWORD_RESET_LINK_OPENED);
    submitReset($token)->assertOk();
});

test('resetting updates the password and its dates and allows signing in only with the new one', function (): void {
    $user = resetUser(['password' => Hash::make('Cc3!'.Str::random(20))]);
    $user->tenants()->attach(Tenant::factory()->create()->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $user->forceFill(['password_reset_required' => true])->save();
    $oldHash = $user->password;

    requestReset();
    submitReset(lastResetToken())->assertOk();

    $user->refresh();

    expect(Hash::check(newPassword(), $user->password))->toBeTrue()
        ->and($user->password)->not->toBe($oldHash)
        ->and($user->password_changed_at)->not->toBeNull()
        ->and($user->last_password_reset_at)->not->toBeNull()
        ->and($user->password_reset_required)->toBeFalse();

    test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => newPassword()])->assertOk();
});

test('resetting revokes every access token and session of that user only', function (): void {
    $user = resetUser();
    $other = User::factory()->create();
    $user->createToken('one');
    $user->createToken('two');
    $other->createToken('other');

    foreach ([$user, $other] as $owner) {
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
    }

    requestReset();
    submitReset(lastResetToken())->assertOk();

    expect($user->tokens()->count())->toBe(0)
        ->and($other->tokens()->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and(resetLedgerActions())->toContain(
            RegistrationAudit::PASSWORD_RESET_COMPLETED,
            RegistrationAudit::PASSWORD_RESET_SESSIONS_REVOKED,
            RegistrationAudit::PASSWORD_CHANGE_NOTIFIED,
        );
});

test('a link stops working when the account is disabled', function (): void {
    $user = resetUser();
    requestReset();
    $user->update(['is_active' => false]);

    submitReset(lastResetToken())->assertStatus(400)->assertJsonPath('type', AuthProblem::PasswordResetTokenInvalid->value);

    expect(Hash::check(newPassword(), $user->fresh()->password))->toBeFalse();
});

test('the user is notified of the change with date, address and device', function (): void {
    $user = resetUser();
    requestReset();

    test()->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/131.0 Safari/537.36'])
        ->postJson('/api/v1/auth/reset-password', [
            'token' => lastResetToken(),
            'password' => newPassword(),
            'password_confirmation' => newPassword(),
        ])->assertOk();

    Mail::assertQueued(PasswordChangedMail::class, fn (PasswordChangedMail $mail): bool => $mail->hasTo($user->email)
        && $mail->device === 'Chrome · macOS'
        && $mail->ipAddress !== null
        && $mail->locale === 'it');
    expect(new ReflectionClass(PasswordChangedMail::class))->implementsInterface(ShouldBeEncrypted::class)->toBeTrue();
});

test('a token cannot be used twice', function (): void {
    resetUser();
    requestReset();
    $token = lastResetToken();

    submitReset($token)->assertOk();
    submitReset($token, 'Dd4!'.Str::random(20))->assertStatus(400)->assertJsonPath('type', AuthProblem::PasswordResetTokenInvalid->value);
    test()->getJson('/api/v1/auth/validate-reset-token?token='.urlencode($token))->assertStatus(400);

    expect(Hash::check(newPassword(), User::where('email', 'giuliabianchitest@example.com')->sole()->password))->toBeTrue()
        ->and(resetLedgerActions())->toContain(RegistrationAudit::PASSWORD_RESET_TOKEN_INVALID);
});

test('expired tokens are rejected', function (): void {
    resetUser();
    requestReset();
    $token = lastResetToken();

    test()->travel((int) config('auth.password_reset.expires_minutes') + 1)->minutes();

    test()->getJson('/api/v1/auth/validate-reset-token?token='.urlencode($token))->assertStatus(410);
    submitReset($token)->assertStatus(410)->assertJsonPath('type', AuthProblem::PasswordResetTokenExpired->value);

    expect(resetLedgerActions())->toContain(RegistrationAudit::PASSWORD_RESET_TOKEN_EXPIRED);
});

test('the link lifetime is configurable', function (): void {
    config(['auth.password_reset.expires_minutes' => 5]);
    resetUser();
    requestReset();

    expect((int) round(now()->diffInMinutes(PasswordResetToken::sole()->expires_at)))->toBe(5);
});

test('forged, malformed or missing tokens are rejected', function (string $query): void {
    test()->getJson('/api/v1/auth/validate-reset-token'.$query)->assertStatus(400);
})->with([
    'missing' => [''],
    'array' => ['?token[]=abc'],
    'without signature' => ['?token=abc'],
    'wrong signature' => ['?token=abc.def'],
]);

test('the new password follows the configurable policy and must be confirmed', function (): void {
    resetUser();
    requestReset();
    $token = lastResetToken();

    submitReset($token, 'short')->assertStatus(422)->assertJsonValidationErrors('password');
    test()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'password' => newPassword(),
        'password_confirmation' => newPassword().'x',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    config(['auth.registration.password.min_length' => 40]);
    submitReset($token)->assertStatus(422)->assertJsonValidationErrors('password');

    // A rejected password does not use up the link.
    config(['auth.registration.password.min_length' => 10]);
    submitReset($token)->assertOk();
});

test('requests beyond the limit for an address are silent, send no link and alert the owner once', function (): void {
    config(['auth.password_reset.rate_limits.request_per_email_per_hour' => 2]);
    $user = resetUser();

    foreach (range(1, 2) as $attempt) {
        requestReset()->assertStatus(202);
    }

    test()->travel(2)->minutes();
    requestReset()->assertStatus(202);
    test()->travel(2)->minutes();
    requestReset()->assertStatus(202);

    Mail::assertQueuedCount(3);
    Mail::assertQueued(PasswordResetMail::class, 2);
    Mail::assertQueued(PasswordResetAlertMail::class, fn (PasswordResetAlertMail $mail): bool => $mail->hasTo($user->email));
    expect(resetLedgerActions())->toContain(RegistrationAudit::PASSWORD_RESET_ATTEMPTS_EXCEEDED);
});

test('an unknown or ineligible address that exceeds the limit gets no alert', function (): void {
    config(['auth.password_reset.rate_limits.request_per_email_per_hour' => 1]);
    resetUser(['is_active' => false]);

    requestReset()->assertStatus(202);
    test()->travel(2)->minutes();
    requestReset()->assertStatus(202);

    Mail::assertNothingQueued();
});

test('requests are rate limited by address', function (): void {
    config(['auth.password_reset.rate_limits.request_per_minute' => 2]);

    requestReset('uno@example.com')->assertStatus(202);
    requestReset('due@example.com')->assertStatus(202);
    requestReset('tre@example.com')->assertStatus(429);
});

test('token attempts are rate limited by address', function (): void {
    config(['auth.password_reset.rate_limits.attempts_per_minute' => 1]);

    test()->getJson('/api/v1/auth/validate-reset-token?token=abc.def')->assertStatus(400);
    test()->getJson('/api/v1/auth/validate-reset-token?token=abc.def')->assertStatus(429);
});

test('repeated failures lock the address temporarily, even with a good token', function (): void {
    config(['auth.password_reset.max_failed_attempts' => 2, 'auth.password_reset.lockout_minutes' => 15]);
    resetUser();
    requestReset();
    $token = lastResetToken();

    foreach (range(1, 2) as $attempt) {
        submitReset('abc.def')->assertStatus(400);
    }

    submitReset($token)->assertStatus(429)->assertJsonPath('type', AuthProblem::PasswordResetLocked->value);
    expect(resetLedgerActions())->toContain(RegistrationAudit::PASSWORD_RESET_ATTEMPTS_EXCEEDED);

    test()->travel(16)->minutes();
    submitReset($token)->assertOk();
});

test('errors are localized', function (string $locale, string $expected): void {
    test()->withHeaders(['X-Language-Code' => $locale])->getJson('/api/v1/auth/validate-reset-token?token=abc.def')
        ->assertStatus(400)
        ->assertJsonPath('title', $expected);
})->with([
    ['it', 'Link non valido'],
    ['en', 'Invalid link'],
    ['de', 'Ungültiger Link'],
    ['fr', 'Lien non valide'],
]);

test('every template renders in the four languages', function (string $locale, string $expected): void {
    config(['mail.support_address' => 'supporto@example.com']);
    $user = resetUser(['locale' => $locale]);

    $mails = [
        new PasswordResetMail($user, 'https://example.com/reset-password?token=abc', now()->addHour()),
        new PasswordChangedMail($user, now(), '203.0.113.7', 'Chrome · macOS'),
        new PasswordResetAlertMail($user),
    ];

    foreach ($mails as $mail) {
        $mail->locale($locale);
        expect($mail->render())->toContain('Giulia')->toContain('supporto@example.com');
    }

    expect($mails[0]->render())->toContain($expected)->toContain('https://example.com/reset-password?token=abc');
    expect($mails[1]->render())->toContain('203.0.113.7')->toContain('Chrome · macOS');
})->with([
    ['it', 'Reimposta password'],
    ['en', 'Reset password'],
    ['de', 'Passwort zurücksetzen'],
    ['fr', 'Réinitialiser le mot de passe'],
]);

test('the translation files have the same keys', function (): void {
    $keys = fn (string $locale): array => array_keys(Arr::dot(require lang_path("{$locale}/password_reset.php")));

    foreach (['en', 'de', 'fr'] as $locale) {
        expect($keys($locale))->toEqual($keys('it'));
    }
});

test('devices are described without exposing the raw user agent', function (?string $userAgent, ?string $expected): void {
    expect(DeviceDescription::from($userAgent))->toBe($expected);
})->with([
    'none' => [null, null],
    'unknown' => ['curl/8.0', null],
    'edge' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/131.0 Safari/537.36 Edg/131.0', 'Edge · Windows'],
    'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1', 'Safari · iOS (iPhone)'],
    'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0', 'Firefox · Linux'],
    'chrome android' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36', 'Chrome · Android'],
]);

test('expired tokens are pruned after the retention period, recent ones are kept', function (): void {
    config(['auth.password_reset.prune_after_days' => 7]);
    $user = resetUser();
    $row = fn (CarbonInterface $expiresAt) => PasswordResetToken::create([
        'user_id' => $user->id,
        'token_hash' => Str::random(64),
        'expires_at' => $expiresAt,
    ]);

    $old = $row(now()->subDays(8));
    $recent = $row(now()->subDays(6));

    test()->artisan('model:prune', ['--model' => [PasswordResetToken::class]])->assertSuccessful();

    expect(PasswordResetToken::find($old->id))->toBeNull()
        ->and(PasswordResetToken::find($recent->id))->not->toBeNull();
});
