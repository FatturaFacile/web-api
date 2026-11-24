<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * A dish or drink of an order, with its course, station and what is already paid of it.
 *
 * @property string $id
 * @property string $order_id
 * @property ?string $product_id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $tax_rate
 * @property int $course
 * @property string $station
 * @property ?string $notes
 * @property string $status
 * @property string $paid_quantity
 */
final class OrderItem extends HospitalityRecord
{
    /** @var list<string> */
    public const array Stations = ['kitchen', 'bar', 'pizza', 'pastry'];

    protected $table = 'hosp_order_items';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'tax_rate' => 'decimal:2', 'paid_quantity' => 'decimal:4', 'course' => 'integer', 'sent_at' => 'immutable_datetime'];
    }

    /**
     * Quantity still to be paid: nothing for a voided item, otherwise ordered less paid.
     */
    public function remainingQuantity(): string
    {
        return $this->status === 'voided' ? '0' : Decimal::sub((string) $this->quantity, (string) $this->paid_quantity);
    }
}
