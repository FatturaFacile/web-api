<?php

declare(strict_types=1);

namespace App\Actions\Ui;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class UpdateTenantBranding
{
    public const KEYS = ['brand_primary_color', 'brand_secondary_color', 'brand_logo_url', 'default_theme'];

    /**
     * Merge the validated branding into the tenant settings (null removes a value); colours are stored upper-case.
     *
     * @param  array<string, mixed>  $validated
     */
    public function execute(string $tenantId, array $validated): Tenant
    {
        return DB::transaction(function () use ($tenantId, $validated): Tenant {
            $tenant = Tenant::lockForUpdate()->findOrFail($tenantId);
            $settings = $tenant->getAttribute('settings');
            $settings = is_array($settings) ? $settings : [];
            $branding = $tenant->branding();

            foreach (array_intersect_key($validated, array_flip(self::KEYS)) as $key => $value) {
                if ($value === null) {
                    unset($branding[$key]);
                } else {
                    $branding[$key] = str_ends_with($key, '_color') ? strtoupper((string) $value) : $value;
                }
            }

            $settings['branding'] = $branding;
            $tenant->update(['settings' => $settings]);

            return $tenant;
        });
    }
}
