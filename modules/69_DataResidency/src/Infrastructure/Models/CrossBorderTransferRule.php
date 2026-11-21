<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Modules\DataResidency\Domain\Enums\TransferDecision;

/**
 * One row of the adequacy matrix: the statutory baseline (tenant_id null) or a tenant override.
 *
 * @property string $source_jurisdiction
 * @property string $destination_country_iso2
 * @property string $destination_country_name
 * @property bool $has_ch_adequacy
 * @property bool $has_eu_adequacy
 * @property TransferDecision $decision_status
 * @property string $legal_basis_reference
 */
final class CrossBorderTransferRule extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'core_cross_border_transfer_rules';

    protected function casts(): array
    {
        return [
            'has_ch_adequacy' => 'boolean',
            'has_eu_adequacy' => 'boolean',
            'decision_status' => TransferDecision::class,
        ];
    }
}
