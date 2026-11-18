<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\PortalSetting;
use Illuminate\Http\Request;

/**
 * Finds the white-label settings of the portal being opened: by the custom domain of the request, or by the portal slug.
 * It runs before any login, so it reads across tenants and only ever returns one row of the exact domain or slug.
 */
final class PortalBrandingResolver
{
    public function resolve(Request $request): ?PortalSetting
    {
        $host = strtolower($request->getHost());

        $byDomain = PortalSetting::withoutGlobalScopes()->where('custom_domain', $host)->first();

        if ($byDomain !== null) {
            return $byDomain;
        }

        $slug = strtolower(trim((string) ($request->query('slug') ?? $request->header('X-Portal-Slug', ''))));

        if ($slug === '') {
            return null;
        }

        return PortalSetting::withoutGlobalScopes()->where('portal_slug', $slug)->first();
    }
}
