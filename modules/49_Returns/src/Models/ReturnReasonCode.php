<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A reason a customer or the company gives for a return. A code in use is deactivated, never deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $label
 * @property bool $is_defect
 * @property bool $is_active
 */
final class ReturnReasonCode extends BaseEnterpriseModel
{
    /**
     * Reason codes every company starts with: code => [label, is_defect].
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const array Defaults = [
        'defective' => ['Defective or not working', true],
        'damaged_in_transit' => ['Damaged in transit', true],
        'wrong_item' => ['Wrong item delivered', false],
        'not_as_described' => ['Not as described', false],
        'changed_mind' => ['Changed mind', false],
        'other' => ['Other', false],
        'right_of_withdrawal' => ['Right of withdrawal / Commercial return', false],
        'commercial_return' => ['Commercial return / Agreement', false],
        'wrong_shipment' => ['Wrong shipment', false],
        'doa' => ['Dead On Arrival (DOA)', true],
        'warranty_defect' => ['Warranty defect', true],
    ];

    protected $table = 'returns_reason_codes';

    protected function casts(): array
    {
        return ['is_defect' => 'boolean', 'is_active' => 'boolean'];
    }
}
