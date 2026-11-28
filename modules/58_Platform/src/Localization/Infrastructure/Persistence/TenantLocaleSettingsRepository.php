<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Persistence;

use App\Models\Tenant;
use Modules\Platform\Localization\Domain\ValueObjects\LocaleSettings;

/**
 * The locale settings live in `tenants.settings.localization` (JSON), so they need no extra table and travel with the tenant.
 */
final class TenantLocaleSettingsRepository
{
    public function get(string $tenantId): LocaleSettings
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return $this->fromTenant($tenant);
    }

    public function country(string $tenantId): ?string
    {
        $country = Tenant::query()->whereKey($tenantId)->value('country');

        return $country === null ? null : (string) $country;
    }

    public function save(string $tenantId, LocaleSettings $settings): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $stored = is_array($tenant->settings) ? $tenant->settings : [];
        $stored['localization'] = $settings->toArray();
        $tenant->settings = $stored;
        $tenant->save();
    }

    public function fromTenant(Tenant $tenant): LocaleSettings
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $localization = isset($settings['localization']) && is_array($settings['localization']) ? $settings['localization'] : [];

        return LocaleSettings::fromArray($localization, (string) $tenant->country);
    }
}
