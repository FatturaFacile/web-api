<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * From which total discount a quote needs approval and which role approves it.
 */
final class DiscountThreshold extends BaseEnterpriseModel
{
    /** Roles allowed to approve, from the lowest to the highest authority. */
    public const array ApproverRoles = ['sales_manager', 'admin', 'owner'];

    protected $table = 'crm_discount_thresholds';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_discount_percent' => 'decimal:2',
        ];
    }
}
