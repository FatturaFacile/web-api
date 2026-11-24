<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Support\Carbon;

/**
 * Official statutory/fiscal depreciation rate catalog (Swiss AFC/ESTV Merkblatt A 1995 & Italian D.M. 31/12/1988).
 * Global statutory rows have tenant_id = null; tenants may also define custom overrides.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $jurisdiction
 * @property string $regime_code
 * @property string $sector_code
 * @property string $sector_name
 * @property string $category_code
 * @property string $category_name
 * @property string|null $declining_rate_percent
 * @property string|null $linear_rate_percent
 * @property bool $first_year_half_rate
 * @property bool $immediate_expensing_allowed
 * @property string|null $immediate_expensing_threshold
 * @property string|null $legal_reference
 * @property array<string, mixed>|null $translations
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class FiscalDepreciationRate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'eam_fiscal_depreciation_rates';

    protected function casts(): array
    {
        return [
            'declining_rate_percent' => 'decimal:4',
            'linear_rate_percent' => 'decimal:4',
            'first_year_half_rate' => 'boolean',
            'immediate_expensing_allowed' => 'boolean',
            'immediate_expensing_threshold' => 'decimal:2',
            'translations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Localized category name for the requested locale (supports it-CH, de-CH, fr-CH, en, it-IT).
     */
    public function localizedCategoryName(string $locale): string
    {
        $translations = $this->translations ?? [];

        if (isset($translations[$locale]) && is_string($translations[$locale]) && $translations[$locale] !== '') {
            return $translations[$locale];
        }

        $base = explode('-', $locale)[0];
        $fallbackOrder = match ($base) {
            'it' => ['it-IT', 'it-CH', 'it', 'en'],
            'de' => ['de-CH', 'de', 'en'],
            'fr' => ['fr-CH', 'fr', 'en'],
            default => ['en'],
        };

        foreach ($fallbackOrder as $candidate) {
            if (isset($translations[$candidate]) && is_string($translations[$candidate]) && $translations[$candidate] !== '') {
                return $translations[$candidate];
            }
        }

        return (string) $this->category_name;
    }
}
