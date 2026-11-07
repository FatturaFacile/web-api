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
 * keeps everything but that policy. HSTS is only sent over HTTPS.
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
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
