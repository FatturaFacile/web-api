<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    use ApiResponse;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_platform_admin) {
            return $this->problemDetailsResponse(
                title: __('registration.errors.forbidden_title'),
                detail: __('registration.errors.forbidden_detail'),
                status: Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
