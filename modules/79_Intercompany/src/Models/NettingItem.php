<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One open item that a netting run offsets.
 *
 * @property string $open_amount
 * @property string $applied_amount
 * @property string $fx_rate
 * @property string $variance
 */
final class NettingItem extends BaseEnterpriseModel
{
    protected $table = 'ic_netting_items';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'open_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'fx_rate' => 'decimal:8',
            'variance' => 'decimal:4',
        ];
    }
}
