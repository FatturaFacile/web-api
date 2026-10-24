<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = ['it', 'de', 'fr', 'en'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $acceptLanguage = $request->header('Accept-Language');

        if (! empty($acceptLanguage)) {
            // Match language prefix e.g. "it-CH,it;q=0.9,en;q=0.8" -> "it"
            $preferred = strtolower(substr($acceptLanguage, 0, 2));
            if (in_array($preferred, self::SUPPORTED_LOCALES, true)) {
                App::setLocale($preferred);
            }
        } elseif ($request->user()?->locale && in_array($request->user()->locale, self::SUPPORTED_LOCALES, true)) {
            App::setLocale($request->user()->locale);
        }

        return $next($request);
    }
}
