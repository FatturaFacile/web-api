<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * First middleware of the global stack (it replaces Laravel's TrustProxies, whose job it does first, so
 * X-Forwarded-Proto is honoured only from the proxies listed in TRUSTED_PROXIES). The system works over HTTPS only, in
 * every environment:
 *
 * - an insecure GET/HEAD is redirected with 308 to the same path and query on the host of `app.url`, never to the Host
 *   header sent by the client (no open redirect through a forged Host);
 * - every other method is refused with 426 and the body is never read, so no credential or payload sent over plain HTTP
 *   is processed;
 * - the paths in `security.https.insecure_allowed_paths` (liveness probe) are answered over plain HTTP for GET/HEAD;
 * - on the way out, every cookie of the response gets the Secure flag, HttpOnly (except the XSRF-TOKEN cookie, which
 *   the SPA must read to send it back in X-XSRF-TOKEN) and a SameSite attribute (Lax when none was set).
 */
final class EnforceHttps extends TrustProxies
{
    use ApiResponse;

    /**
     * Cookies the browser script must be able to read.
     *
     * @var list<string>
     */
    private const array SCRIPT_READABLE_COOKIES = ['XSRF-TOKEN'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        return parent::handle($request, function (Request $request) use ($next): Response {
            if ($request->isSecure()) {
                return $this->hardenCookies($next($request));
            }

            if ($this->isAllowedInsecure($request)) {
                return $next($request);
            }

            return in_array($request->method(), ['GET', 'HEAD'], true)
                ? $this->redirectToHttps($request)
                : $this->refuse();
        });
    }

    private function isAllowedInsecure(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD'], true)
            && $request->is(...(array) config('security.https.insecure_allowed_paths', []));
    }

    private function redirectToHttps(Request $request): Response
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);
        $authority = $host.($port !== null && $port !== 443 ? ':'.$port : '');

        return redirect()->away('https://'.$authority.$request->getRequestUri(), Response::HTTP_PERMANENTLY_REDIRECT);
    }

    private function refuse(): Response
    {
        return $this->problemDetailsResponse(
            title: 'HTTPS Required',
            detail: 'This API is served over HTTPS only. The request was refused and its content was not processed: repeat it over https.',
            status: Response::HTTP_UPGRADE_REQUIRED,
        )->withHeaders(['Upgrade' => 'TLS/1.2, HTTP/1.1', 'Connection' => 'Upgrade']);
    }

    private function hardenCookies(Response $response): Response
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $hardened = $cookie->withSecure(true)
                ->withHttpOnly(! in_array($cookie->getName(), self::SCRIPT_READABLE_COOKIES, true))
                ->withSameSite($cookie->getSameSite() ?? Cookie::SAMESITE_LAX);

            $response->headers->setCookie($hardened);
        }

        return $response;
    }
}
