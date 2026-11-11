<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Integrations\Http\Middleware\AuthenticateApiKey;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The "array" session driver keeps nothing between requests: the browser flow needs a persistent one.
    config(['session.driver' => 'database']);
    $this->password = Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $this->tenant = Tenant::factory()->create();
    $this->user = User::create([
        'name' => 'Carla Verdi',
        'email' => 'carla.verdi@example.com',
        'password' => Hash::make($this->password),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
});

/**
 * Value of a cookie as the browser stores it (still encrypted), or null.
 */
function cookieOf(TestResponse $response, string $name): ?string
{
    $cookie = collect($response->headers->getCookies())->first(fn (Cookie $cookie): bool => $cookie->getName() === $name);

    return $cookie?->getValue();
}

/**
 * The SPA flow of a browser: GET /sanctum/csrf-cookie, then the cookies it received are sent back with every call.
 * CSRF is enforced for real: Laravel skips it only in the "testing" environment, so the tests flip the name of the
 * environment for the duration of the call.
 *
 * @return array{cookies: array<string, string>, xsrf: string}
 */
function browserSession(): array
{
    $response = test()->withHeaders(spaHeaders())->get('/sanctum/csrf-cookie')->assertNoContent();

    return [
        'cookies' => [(string) config('session.cookie') => (string) cookieOf($response, (string) config('session.cookie'))],
        'xsrf' => (string) cookieOf($response, 'XSRF-TOKEN'),
    ];
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array{cookies: array<string, string>, xsrf: string|null}  $browser
 */
function browserCall(string $method, string $uri, array $payload, array $browser, bool $withCsrf = true): TestResponse
{
    $headers = spaHeaders() + ['X-Tenant-Id' => test()->tenant->id];

    if ($withCsrf && $browser['xsrf'] !== null) {
        $headers['X-XSRF-TOKEN'] = $browser['xsrf'];
    }

    // A real browser call is a new PHP process: nothing of the previous request stays in the session store or the guards.
    test()->flushHeaders();
    app('session')->driver()->flush();
    app()['auth']->forgetGuards();
    $environment = app()['env'];
    app()['env'] = 'local';

    try {
        return test()->withCredentials()->withHeaders($headers)->withUnencryptedCookies($browser['cookies'])->json($method, $uri, $payload);
    } finally {
        app()['env'] = $environment;
    }
}

test('login answers with the session cookie and never with a token', function (): void {
    $response = $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_type')
        ->assertJsonMissingPath('data.expires_at');

    expect($response->getContent())->not->toContain('Bearer')
        ->and(PersonalAccessToken::count())->toBe(0)
        ->and(cookieOf($response, (string) config('session.cookie')))->not->toBeNull();
});

test('the token mode is off by default and the flag is a configuration key', function (): void {
    expect(config('auth.token_mode.enabled'))->toBeFalse();
});

test('asking for a token while the token mode is off is a 422 on token_mode', function (): void {
    $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password, 'token_mode' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token_mode');

    // Also without a stateful origin, and before anybody looks at the credentials.
    $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'wrong', 'token_mode' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token_mode');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('with the token mode on, a token is issued only to the client that asks for it', function (): void {
    config(['auth.token_mode.enabled' => true]);

    $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonMissingPath('data.token');

    $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password, 'token_mode' => true])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'token')
        ->assertJsonPath('data.token_type', 'Bearer');

    expect(PersonalAccessToken::count())->toBe(1);
});

test('a Bearer token is not accepted as the credential of a user while the token mode is off', function (): void {
    $token = $this->user->createToken('legacy')->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}", 'X-Tenant-Id' => $this->tenant->id];

    $this->withHeaders($headers)->getJson('/api/v1/auth/profile')->assertUnauthorized();

    // The row is kept (inert), and works again only if the mode is switched on.
    expect(PersonalAccessToken::count())->toBe(1);
    config(['auth.token_mode.enabled' => true]);
    app()['auth']->forgetGuards();
    $this->withHeaders($headers)->getJson('/api/v1/auth/profile')->assertOk();
});

test('a login that cannot open a cookie session is a 400 and not a token', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password])
        ->assertStatus(400)
        ->assertJsonPath('type', 'urn:fatturafacile:problem:cookie-session-required');

    $this->withHeaders(['Origin' => 'http://localhost:4200', 'Referer' => 'http://localhost:4200/'])
        ->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password])
        ->assertStatus(400);

    $this->withHeaders(['Origin' => 'https://evil.example', 'Referer' => 'https://evil.example/'])
        ->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password])
        ->assertStatus(400);

    expect(PersonalAccessToken::count())->toBe(0);
});

test('without the csrf cookie the login fails with 419, with it the login works', function (): void {
    $browser = browserSession();
    $credentials = ['email' => $this->user->email, 'password' => $this->password];

    browserCall('POST', '/api/v1/auth/login', $credentials, $browser, withCsrf: false)->assertStatus(419);
    browserCall('POST', '/api/v1/auth/login', $credentials, ['cookies' => $browser['cookies'], 'xsrf' => 'forged'])->assertStatus(419);
    browserCall('POST', '/api/v1/auth/login', $credentials, $browser)->assertOk()->assertJsonPath('data.auth_mode', 'cookie');
});

test('the browser session opens the protected routes and logout invalidates it', function (): void {
    $browser = browserSession();
    $login = browserCall('POST', '/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password], $browser)->assertOk();

    $browser['cookies'][(string) config('session.cookie')] = (string) cookieOf($login, (string) config('session.cookie'));
    $browser['xsrf'] = cookieOf($login, 'XSRF-TOKEN') ?? $browser['xsrf'];

    browserCall('GET', '/api/v1/auth/profile', [], $browser)->assertOk()->assertJsonPath('data.email', $this->user->email);

    // A write without the CSRF header is refused even with a valid session.
    browserCall('POST', '/api/v1/auth/logout', [], $browser, withCsrf: false)->assertStatus(419);
    browserCall('GET', '/api/v1/auth/profile', [], $browser)->assertOk();

    $logout = browserCall('POST', '/api/v1/auth/logout', [], $browser)->assertOk();

    // The old cookie opens nothing any more.
    browserCall('GET', '/api/v1/auth/profile', [], $browser)->assertUnauthorized();
    expect(cookieOf($logout, (string) config('session.cookie')))->not->toBeNull();
});

test('the session id is regenerated at login, so a fixated session cannot be used', function (): void {
    $browser = browserSession();
    $before = app()['encrypter']->decrypt($browser['cookies'][(string) config('session.cookie')], false);

    $login = browserCall('POST', '/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password], $browser)->assertOk();
    $after = app()['encrypter']->decrypt((string) cookieOf($login, (string) config('session.cookie')), false);

    expect($after)->not->toBe($before);

    // The pre-login cookie (the one an attacker could have planted) is not authenticated.
    browserCall('GET', '/api/v1/auth/profile', [], $browser)->assertUnauthorized();
});

test('the two-factor challenge completes in cookie mode and the challenge keeps the mode of the login', function (): void {
    $totp = app(Totp::class);
    $secret = $totp->generateSecret();
    $this->user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []])->save();

    $challenge = $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password])
        ->assertOk()
        ->json('data.challenge_token');

    // Without a session the code is not even consumed.
    $this->flushHeaders();
    $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $totp->codeAt($secret, time())])->assertStatus(400);

    $response = $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $totp->codeAt($secret, time())])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonMissingPath('data.token');

    expect(cookieOf($response, (string) config('session.cookie')))->not->toBeNull()
        ->and(PersonalAccessToken::count())->toBe(0);
});

test('a challenge started in token mode ends in token mode', function (): void {
    config(['auth.token_mode.enabled' => true]);
    $totp = app(Totp::class);
    $secret = $totp->generateSecret();
    $this->user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []])->save();

    $challenge = $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password, 'token_mode' => true])->json('data.challenge_token');

    $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $totp->codeAt($secret, time())])
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'token');
});

test('refresh of a cookie session renews it without a token in the body', function (): void {
    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $this->password])->assertOk();

    $this->withHeaders([...spaHeaders(), 'X-Tenant-Id' => $this->tenant->id])
        ->postJson('/api/v1/auth/refresh')
        ->assertOk()
        ->assertJsonPath('data.auth_mode', 'cookie')
        ->assertJsonMissingPath('data.token');
});

test('integration API keys keep working with the token mode off', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer ff_'.Str::random(40)])->getJson('/api/v1/auth/profile')->assertUnauthorized();

    expect(config('auth.token_mode.enabled'))->toBeFalse()
        ->and(class_exists(AuthenticateApiKey::class))->toBeTrue();
});
