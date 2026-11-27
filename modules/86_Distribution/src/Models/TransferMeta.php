<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Transport data of a transfer order: the lane it follows, the carrier, the tracking number, the transport document (DDT)
 * and the expected arrival date.
 *
 * @property string $transfer_order_id
 * @property string|null $lane_id
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property string|null $ddt_reference
 * @property Carbon|null $expected_arrival_date
 * @property Carbon|null $dispatched_at
 */
final class TransferMeta extends BaseEnterpriseModel
{
    protected $table = 'distribution_transfer_meta';

    protected function casts(): array
    {
        return [
            'expected_arrival_date' => 'date',
            'dispatched_at' => 'datetime',
        ];
    }
}
