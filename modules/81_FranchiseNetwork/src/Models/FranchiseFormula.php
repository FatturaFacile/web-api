<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Modules\FranchiseNetwork\Domain\Enums\StartupMode;

/**
 * Royalty and marketing fund formula: progressive brackets, minimum guarantee and start-up exemption. Presets have a null tenant.
 *
 * @property string $code
 * @property array<string, mixed> $labels
 * @property array<string, mixed>|null $descriptions
 * @property bool $is_preset
 * @property list<array<string, mixed>> $royalty_tiers
 * @property list<array<string, mixed>> $marketing_tiers
 * @property string $minimum_guarantee
 * @property int $startup_months
 * @property StartupMode $startup_mode
 * @property string $startup_reduction_percent
 * @property string $ramp_start_percent
 * @property bool $startup_applies_to_marketing
 * @property string $currency
 * @property bool $is_active
 */
final class FranchiseFormula extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'frn_formulas';

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'descriptions' => 'array',
            'is_preset' => 'boolean',
            'royalty_tiers' => 'array',
            'marketing_tiers' => 'array',
            'minimum_guarantee' => 'decimal:2',
            'startup_months' => 'integer',
            'startup_mode' => StartupMode::class,
            'startup_reduction_percent' => 'decimal:2',
            'ramp_start_percent' => 'decimal:2',
            'startup_applies_to_marketing' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
