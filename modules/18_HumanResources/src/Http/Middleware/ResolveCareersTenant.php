<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\HumanResources\Models\RecruitingSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant of the public careers endpoints from the `{careersSlug}` route parameter only, sets the
 * tenant context explicitly for the request and always clears it afterwards. Unknown or disabled slugs are 404.
 */
final readonly class ResolveCareersTenant
{
    public const string SETTINGS_ATTRIBUTE = 'recruiting_settings';

    public function __construct(
        private TenantContext $tenantContext
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = strtolower(trim((string) $request->route('careersSlug')));

        /** @var RecruitingSettings|null $settings */
        $settings = $slug === '' ? null : RecruitingSettings::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('careers_enabled', true)
            ->where('careers_slug', $slug)
            ->first();

        $tenant = $settings !== null ? Tenant::query()->find($settings->tenant_id) : null;

        if ($settings === null || $tenant === null || ($tenant->status ?? 'active') !== 'active') {
            return response()->json(['message' => 'Careers page not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->tenantContext->setTenant($tenant);
        $request->attributes->set(self::SETTINGS_ATTRIBUTE, $settings);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
