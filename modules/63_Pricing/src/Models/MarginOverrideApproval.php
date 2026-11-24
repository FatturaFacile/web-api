<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A supervisor's authorization of a below-threshold document.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $document_type
 * @property string $document_id
 * @property string $calculated_margin_percent
 * @property string $threshold_percent
 * @property string $approved_by_user_id
 */
final class MarginOverrideApproval extends BaseEnterpriseModel
{
    protected $table = 'margin_override_approvals';

    protected function casts(): array
    {
        return [
            'calculated_margin_percent' => 'decimal:2',
            'threshold_percent' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }
}
