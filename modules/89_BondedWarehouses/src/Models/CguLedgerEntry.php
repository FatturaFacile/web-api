<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Concerns\ImmutableRecord;
use Modules\BondedWarehouses\Domain\Enums\CguDirection;
use Modules\BondedWarehouses\Domain\Enums\CguReferenceType;

/**
 * Append-only ledger of the engagement and release of the comprehensive guarantee (CGU) ceiling.
 *
 * @property string $authorization_id
 * @property CguReferenceType $reference_type
 * @property string|null $reference_id
 * @property CguDirection $movement_direction
 * @property string $duty_amount
 * @property string $vat_amount
 * @property string $total_cgu_delta
 * @property string $running_utilized_balance
 * @property string $remaining_plafond
 * @property Carbon $recorded_at
 */
final class CguLedgerEntry extends BaseEnterpriseModel
{
    use ImmutableRecord;

    protected $table = 'customs_cgu_guarantee_ledger';

    protected function casts(): array
    {
        return [
            'reference_type' => CguReferenceType::class,
            'movement_direction' => CguDirection::class,
            'duty_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_cgu_delta' => 'decimal:2',
            'running_utilized_balance' => 'decimal:2',
            'remaining_plafond' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }
}
