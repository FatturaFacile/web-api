<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the consolidation endpoints out of reach of API keys. A key is issued for one tenant and one module scope, while
 * a consolidation reads the ledgers of several entities and must prove that the person asking can read each of them,
 * which only a signed-in user with memberships can do.
 */
final class RejectApiKeyAccess
{
    use ApiResponse;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (is_array($request->attributes->get('api_key_scopes'))) {
            return $this->problemDetailsResponse(
                title: 'Forbidden Credential',
                detail: 'Consolidation is not available to API keys: sign in as a user.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
