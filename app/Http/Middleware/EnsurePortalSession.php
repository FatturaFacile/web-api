<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Portal\PortalTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Authenticates the self-service portal: a bearer session of a customer (never a staff user), bound to its tenant.
 * The session token is stored on the request as `portal_token`; every portal query scopes on its contact.
 */
final class EnsurePortalSession
{
    public function __construct(private readonly PortalTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            abort(Response::HTTP_UNAUTHORIZED, __('portal.errors.session_required'));
        }

        try {
            $session = $this->tokens->resolveToken($bearer, PortalTokenService::SESSION_TOKEN_TYPE);
        } catch (HttpException) {
            abort(Response::HTTP_UNAUTHORIZED, __('portal.errors.session_required'));
        }

        $request->attributes->set('portal_token', $session);

        return $next($request);
    }
}
