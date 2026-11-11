<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers on every response. The application serves JSON and files, never pages of its own, so the content
 * security policy forbids everything (`default-src 'none'`): a file opened in the browser cannot run scripts or be
 * framed. The interactive API documentation is the exception: it is an HTML page with its own scripts and styles, and
 * keeps everything but that policy. HSTS (`security.hsts`) is sent on every HTTPS response, never over plain HTTP.
 */
final class SecurityHeaders
{
    public const string CONTENT_SECURITY_POLICY = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /**
     * Paths of the HTML documentation pages (Swagger UI and Scramble), which load their own scripts and styles.
     *
     * @var list<string>
     */
    private const array DOCUMENTATION_PATHS = ['api/documentation', 'api/documentation/*', 'api/oauth2-callback', 'docs/api'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');

        if (! $request->is(...self::DOCUMENTATION_PATHS) && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', $this->strictTransportSecurity());
        }

        return $response;
    }

    /**
     * The HSTS value from `security.hsts`: max-age one year and includeSubDomains by default, no preload unless asked.
     */
    private function strictTransportSecurity(): string
    {
        $value = 'max-age='.max(0, (int) config('security.hsts.max_age', 31536000));

        if (config('security.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        if (config('security.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }
}
