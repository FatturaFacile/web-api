<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantBrandingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $branding = $this->branding();

        return [
            'brand_primary_color' => $branding['brand_primary_color'] ?? null,
            'brand_secondary_color' => $branding['brand_secondary_color'] ?? null,
            'brand_logo_url' => $branding['brand_logo_url'] ?? null,
            'default_theme' => $branding['default_theme'] ?? 'system',
        ];
    }
}
