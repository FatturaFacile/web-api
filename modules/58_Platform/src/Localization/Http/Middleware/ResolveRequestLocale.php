<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Modules\Platform\Localization\Domain\Services\LocaleResolver;
use Modules\Platform\Localization\Infrastructure\Persistence\TenantLocaleSettingsRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application language of a localization request from the resolution chain user preference, Accept-Language,
 * tenant default, English, so the validation errors (422) come back in the language of the caller. The resolved locale
 * (with region) is kept as the `resolved_locale` request attribute.
 */
final class ResolveRequestLocale
{
    public function __construct(
        private readonly LocaleResolver $resolver,
        private readonly TenantContext $tenantContext,
        private readonly TenantLocaleSettingsRepository $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->tenantContext->getTenantId();
        $user = $request->user();

        $locale = $this->resolver->resolve(
            $user !== null && is_string($user->locale ?? null) ? $user->locale : null,
            $request->header('Accept-Language'),
            $tenantId !== null ? $this->settings->get($tenantId)->defaultLocale->value : null,
            $tenantId !== null ? $this->settings->country($tenantId) : null,
        );

        $request->attributes->set('resolved_locale', $locale);
        App::setLocale($locale->language());

        return $next($request);
    }
}
