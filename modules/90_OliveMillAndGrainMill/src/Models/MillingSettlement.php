<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\SettlementMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\TariffMode;

/**
 * Settlement of a milling service: cash, in kind (molenda) or hybrid, with the service invoice, the purchase document for the retained oil and the SIAN load.
 *
 * @property string $delivery_id
 * @property string $cycle_id
 * @property TariffMode $tariff_mode
 * @property string $tariff_value
 * @property string $service_net
 * @property string $service_vat_rate
 * @property string $service_vat
 * @property string $service_total
 * @property SettlementMode $settlement_mode
 * @property string|null $molenda_percent
 * @property string $retained_kg
 * @property string $oil_price_per_kg
 * @property string $retained_value_net
 * @property string $purchase_vat_rate
 * @property string $purchase_vat
 * @property string $purchase_total
 * @property string $cash_balance
 * @property string $compensation_amount
 * @property string|null $tank_id
 * @property string|null $service_invoice_id
 * @property string|null $purchase_doc_id
 * @property string|null $sian_movement_id
 * @property string $status
 * @property Carbon|null $settled_at
 * @property string|null $created_by
 */
final class MillingSettlement extends BaseEnterpriseModel
{
    protected $table = 'mill_settlements';

    protected function casts(): array
    {
        return [
            'tariff_mode' => TariffMode::class,
            'tariff_value' => 'decimal:4',
            'service_net' => 'decimal:2',
            'service_vat_rate' => 'decimal:2',
            'service_vat' => 'decimal:2',
            'service_total' => 'decimal:2',
            'settlement_mode' => SettlementMode::class,
            'molenda_percent' => 'decimal:3',
            'retained_kg' => 'decimal:3',
            'oil_price_per_kg' => 'decimal:4',
            'retained_value_net' => 'decimal:2',
            'purchase_vat_rate' => 'decimal:2',
            'purchase_vat' => 'decimal:2',
            'purchase_total' => 'decimal:2',
            'cash_balance' => 'decimal:2',
            'compensation_amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OliveDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(OliveDelivery::class, 'delivery_id');
    }

    /**
     * @return BelongsTo<MillingCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(MillingCycle::class, 'cycle_id');
    }
}
