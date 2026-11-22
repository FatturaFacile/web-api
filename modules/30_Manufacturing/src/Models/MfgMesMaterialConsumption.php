<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Raw material or component consumed on a phase from a lot (Italian: materia prima utilizzata, lotto). The stock
 * movement is the material movement of the order; `settled_at` marks the consumption as already offset against the
 * backflush of the operation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phase_id
 * @property string $production_order_line_id
 * @property string $mfg_item_id
 * @property string|null $lot_id
 * @property string|null $lot_code
 * @property string $quantity
 * @property string|null $material_movement_id
 * @property string|null $operator_id
 * @property string $idempotency_key
 * @property Carbon $consumed_at
 * @property Carbon|null $settled_at
 */
final class MfgMesMaterialConsumption extends BaseEnterpriseModel
{
    protected $table = 'mfg_mes_material_consumptions';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'consumed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
