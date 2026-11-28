<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianStatus;

/**
 * Operation of the SIAN telematic oil register. The quantities and the causal never change after the fact: only the transmission state does.
 *
 * @property int $progressive
 * @property Carbon $operation_date
 * @property Carbon $transmission_deadline
 * @property SianCausalCode $causal
 * @property string|null $from_tank_id
 * @property string|null $to_tank_id
 * @property string|null $counterparty_contact_id
 * @property string|null $counterparty_name
 * @property string|null $counterparty_cuaa
 * @property string $quantity_kg
 * @property string $quantity_l
 * @property OilCategory|null $category
 * @property OriginDesignation|null $origin_designation
 * @property string|null $lot_code
 * @property string|null $bottle_format
 * @property int|null $bottle_count
 * @property string|null $package_lot
 * @property Carbon|null $best_before
 * @property SianStatus $status
 * @property string|null $sian_protocol
 * @property string|null $payload_snapshot
 * @property Carbon|null $transmitted_at
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $error_message
 * @property string|null $notes
 * @property string|null $created_by
 */
final class SianMovement extends BaseEnterpriseModel
{
    protected $table = 'mill_sian_movements';

    protected function casts(): array
    {
        return [
            'progressive' => 'integer',
            'operation_date' => 'date',
            'transmission_deadline' => 'date',
            'causal' => SianCausalCode::class,
            'quantity_kg' => 'decimal:3',
            'quantity_l' => 'decimal:3',
            'category' => OilCategory::class,
            'origin_designation' => OriginDesignation::class,
            'bottle_count' => 'integer',
            'best_before' => 'date',
            'status' => SianStatus::class,
            'transmitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SianTank, $this>
     */
    public function fromTank(): BelongsTo
    {
        return $this->belongsTo(SianTank::class, 'from_tank_id');
    }

    /**
     * @return BelongsTo<SianTank, $this>
     */
    public function toTank(): BelongsTo
    {
        return $this->belongsTo(SianTank::class, 'to_tank_id');
    }
}
