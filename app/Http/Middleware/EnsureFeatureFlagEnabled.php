<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates routes belonging to premature or feature-flagged ERP modules.
 */
class EnsureFeatureFlagEnabled
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $explicit = config("features.modules.{$flag}");

        if ($explicit === false || $explicit === 'false' || $explicit === '0') {
            abort(404, "Feature module [{$flag}] is not enabled.");
        }

        if ($explicit === null && ! (bool) config('features.open_issue_modules_enabled', true)) {
            abort(404, "Feature module [{$flag}] is not enabled in this release.");
        }

        return $next($request);
    }
}
