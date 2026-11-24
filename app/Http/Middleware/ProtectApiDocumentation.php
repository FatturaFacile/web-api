<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OpenAPI specification maps every endpoint of the platform. With docs.require_auth on (the default in production)
 * only a signed-in user allowed by the viewApiDocs gate reads it: a platform administrator or an address listed in
 * DOCS_ALLOWED_EMAILS. The user signs in with the usual bearer token or with the web session.
 */
final class ProtectApiDocumentation
{
    use ApiResponse;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('docs.require_auth')) {
            return $next($request);
        }

        $user = $request->user() ?? $request->user('sanctum');

        if (! $user instanceof User) {
            return $this->problemDetailsResponse(
                title: 'Unauthenticated',
                detail: 'The API documentation requires authentication.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        if (! Gate::forUser($user)->allows('viewApiDocs')) {
            return $this->problemDetailsResponse(
                title: 'Forbidden',
                detail: 'Your account cannot read the API documentation.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
