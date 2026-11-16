<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Logistics\Support\LogisticsPermissions;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLogisticsPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! LogisticsPermissions::allows($request->user(), $permission)) {
            return response()->json(['message' => 'This action is unauthorized.', 'required_permission' => $permission], 403);
        }

        return $next($request);
    }
}
