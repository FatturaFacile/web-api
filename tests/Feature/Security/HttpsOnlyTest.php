<?php

declare(strict_types=1);

use App\Exceptions\InsecureConfigurationException;
use App\Http\Middleware\EnsureHttpsFrontendRequestsAreStateful;
use App\Providers\HttpsServiceProvider;
use App\Support\Security\HttpsRequirements;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A configuration that is HTTPS-only, with some settings changed.
 *
 * @param  array<string, mixed>  $changes
 */
function httpsConfig(array $changes = []): Repository
{
    return new Repository(array_merge([
        'app.url' => 'https://api.example.com',
        'app.frontend_url' => 'https://app.example.com',
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'session.domain' => null,
        'session.path' => '/',
        'session.cookie' => '__Host-session',
        'cors.allowed_origins' => ['https://app.example.com'],
        'cors.allowed_origins_patterns' => [],
        'sanctum.stateful' => ['app.example.com', 'localhost:4200'],
    ], $changes));
}

test('the testing environment itself runs over https', function (): void {
    expect(config('app.url'))->toStartWith('https://')
        ->and(app()->environment('testing'))->toBeTrue()
        ->and(request()->isSecure())->toBeTrue();
});

test('a plain http GET or HEAD is redirected with 308 to the https URL on the host of app.url', function (): void {
    $this->get('http://localhost/api/v1/lookups?x=1')
        ->assertStatus(308)
        ->assertHeader('Location', 'https://localhost/api/v1/lookups?x=1');

    $this->call('HEAD', 'http://localhost/api/v1/lookups')->assertStatus(308);
});

test('the redirect never follows the Host header sent by the client', function (): void {
    $this->get('http://evil.example/api/v1/lookups')
        ->assertStatus(308)
        ->assertHeader('Location', 'https://localhost/api/v1/lookups');
});

test('every other method over plain http is refused with 426 and its body is never processed', function (string $method): void {
    $reached = 0;
    Route::match([$method], '/_test/insecure-body', function () use (&$reached) {
        $reached++;

        return response()->json(['ok' => true]);
    });

    $this->json($method, 'http://localhost/_test/insecure-body', ['password' => 'never-read'])
        ->assertStatus(426)
        ->assertHeader('Upgrade', 'TLS/1.2, HTTP/1.1')
        ->assertJsonPath('status', 426);

    expect($reached)->toBe(0);
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

test('a login over plain http is refused before any credential is looked at', function (): void {
    $this->postJson('http://localhost/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'x'])
        ->assertStatus(426);
});

test('the same requests work over https', function (): void {
    $this->get('https://localhost/up')->assertOk();
    $this->getJson('https://localhost/api/v1/does-not-exist')->assertNotFound();
});

test('only the liveness probe is answered over plain http, and only for GET and HEAD', function (): void {
    $this->get('http://localhost/up')->assertOk();
    $this->post('http://localhost/up')->assertStatus(426);
    $this->get('http://localhost/api/v1/lookups')->assertStatus(308);
});

test('behind a reverse proxy the scheme comes from X-Forwarded-Proto, from trusted proxies only', function (): void {
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('http://localhost/api/v1/does-not-exist')->assertNotFound();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://localhost/api/v1/lookups')
        ->assertStatus(308);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->postJson('http://localhost/api/v1/auth/login', [])
        ->assertStatus(426);
});

test('HSTS is sent with one year and includeSubDomains, without preload by default, and follows the configuration', function (): void {
    $this->getJson('/api/v1/does-not-exist')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    config(['security.hsts' => ['max_age' => 600, 'include_subdomains' => false, 'preload' => true]]);
    $this->getJson('/api/v1/does-not-exist')->assertHeader('Strict-Transport-Security', 'max-age=600; preload');

    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

test('every cookie of a response is Secure and SameSite, and HttpOnly except the XSRF cookie', function (): void {
    Route::get('/_test/cookies', fn () => response('ok')
        ->withCookie(new Cookie('plain', '1', 0, '/', null, false, false, false, null))
        ->withCookie(new Cookie('XSRF-TOKEN', '1', 0, '/', null, false, false, false, null)));

    $response = $this->get('/_test/cookies');
    $cookies = collect($response->headers->getCookies())->keyBy(fn (Cookie $cookie): string => $cookie->getName());

    expect($cookies['plain']->isSecure())->toBeTrue()
        ->and($cookies['plain']->isHttpOnly())->toBeTrue()
        ->and($cookies['plain']->getSameSite())->toBe('lax')
        ->and($cookies['XSRF-TOKEN']->isSecure())->toBeTrue()
        ->and($cookies['XSRF-TOKEN']->isHttpOnly())->toBeFalse()
        ->and($cookies['XSRF-TOKEN']->getSameSite())->toBe('lax');
});

test('the CSRF and the session cookies of the Sanctum SPA flow carry the flags', function (): void {
    $response = $this->withHeaders(spaHeaders())->get('/sanctum/csrf-cookie')->assertNoContent();
    $cookies = collect($response->headers->getCookies())->keyBy(fn (Cookie $cookie): string => $cookie->getName());

    expect($cookies)->toHaveKeys(['XSRF-TOKEN', config('session.cookie')])
        ->and($cookies[config('session.cookie')]->isSecure())->toBeTrue()
        ->and($cookies[config('session.cookie')]->isHttpOnly())->toBeTrue()
        ->and($cookies[config('session.cookie')]->getSameSite())->toBe('lax')
        ->and($cookies[config('session.cookie')]->getPath())->toBe('/')
        ->and($cookies[config('session.cookie')]->getDomain())->toBeNull()
        ->and($cookies['XSRF-TOKEN']->isSecure())->toBeTrue()
        ->and($cookies['XSRF-TOKEN']->isHttpOnly())->toBeFalse();
});

test('the session cookie uses the __Host- prefix, which the Sanctum flow allows because it needs no Domain and Path is /', function (): void {
    expect(config('session.cookie'))->toStartWith('__Host-')
        ->and(config('session.domain'))->toBeNull()
        ->and(config('session.path'))->toBe('/');
});

test('an Origin or Referer served over plain http is never a stateful frontend', function (): void {
    $http = Request::create('https://localhost/api/v1/auth/login', 'POST', server: ['HTTP_ORIGIN' => 'http://localhost:4200', 'HTTP_REFERER' => 'http://localhost:4200/']);
    $https = Request::create('https://localhost/api/v1/auth/login', 'POST', server: ['HTTP_ORIGIN' => 'https://localhost:4200', 'HTTP_REFERER' => 'https://localhost:4200/']);

    expect(EnsureHttpsFrontendRequestsAreStateful::fromFrontend($http))->toBeFalse()
        ->and(EnsureHttpsFrontendRequestsAreStateful::fromFrontend($https))->toBeTrue();
});

test('CORS accepts credentials from the configured https origins only', function (): void {
    $allowed = $this->options('https://localhost/api/v1/auth/login', [], ['Origin' => 'https://localhost:4200', 'Access-Control-Request-Method' => 'POST']);
    $allowed->assertHeader('Access-Control-Allow-Origin', 'https://localhost:4200')->assertHeader('Access-Control-Allow-Credentials', 'true');

    $http = $this->options('https://localhost/api/v1/auth/login', [], ['Origin' => 'http://localhost:4200', 'Access-Control-Request-Method' => 'POST']);
    expect($http->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

test('every generated link is https: named routes, signed URLs and the SAML callback URLs', function (): void {
    expect(route('auth.login'))->toStartWith('https://localhost/')
        ->and(URL::signedRoute('auth.login'))->toStartWith('https://localhost/')
        ->and(route('auth.saml.acs', ['tenantId' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d']))->toStartWith('https://localhost/')
        ->and(url('/storage/x'))->toStartWith('https://localhost/')
        ->and(config('filesystems.disks.public.url'))->toStartWith('https://');
});

test('an HTTPS-only configuration has no problem', function (): void {
    expect(HttpsRequirements::problems(httpsConfig()))->toBe([])
        ->and(HttpsRequirements::problems(app('config')))->toBe([]);
});

test('boot refuses a plain-http app.url with a message naming the setting', function (): void {
    config(['app.url' => 'http://localhost']);

    expect(fn () => (new HttpsServiceProvider($this->app))->boot())
        ->toThrow(InsecureConfigurationException::class, 'app.url (APP_URL) must be an https URL');
});

test('boot refuses every insecure setting', function (string $key, mixed $value, string $message): void {
    $config = httpsConfig([$key => $value]);

    expect(fn () => HttpsRequirements::assert($config))->toThrow(InsecureConfigurationException::class, $message);
})->with([
    'frontend url' => ['app.frontend_url', 'http://localhost:4200', 'app.frontend_url'],
    'no host' => ['app.url', 'https://', 'app.url'],
    'cookie not secure' => ['session.secure', false, 'session.secure'],
    'cookie readable by scripts' => ['session.http_only', false, 'session.http_only'],
    'samesite none' => ['session.same_site', 'none', 'session.same_site'],
    'http cors origin' => ['cors.allowed_origins', ['https://app.example.com', 'http://app.example.com'], 'cors.allowed_origins'],
    'wildcard cors origin' => ['cors.allowed_origins', ['*'], 'cors.allowed_origins'],
    'http cors pattern' => ['cors.allowed_origins_patterns', ['#^http://.*\.example\.com$#'], 'cors.allowed_origins_patterns'],
    'stateful domain with scheme' => ['sanctum.stateful', ['https://app.example.com'], 'sanctum.stateful'],
    'host cookie with a domain' => ['session.domain', '.example.com', '__Host-'],
    'host outside the session domain' => ['session.domain', 'other.org', 'session.domain'],
]);

test('a session domain that covers the api and the frontend is consistent', function (): void {
    expect(HttpsRequirements::problems(httpsConfig(['session.domain' => '.example.com', 'session.cookie' => 'ff_session'])))->toBe([]);
});

test('every problem is listed at once', function (): void {
    $problems = HttpsRequirements::problems(httpsConfig(['app.url' => 'http://x', 'session.secure' => false]));

    expect($problems)->toHaveCount(2);
});
