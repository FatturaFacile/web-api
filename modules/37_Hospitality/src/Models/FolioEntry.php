<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of the guest folio: a charge (room, city tax, extras, restaurant) or a payment. Voided lines stay for the audit trail.
 *
 * @property string $id
 * @property string $stay_id
 * @property string $entry_type
 * @property string $category
 * @property string $description
 * @property string $quantity
 * @property string $unit_amount
 * @property string $amount
 * @property string|null $payment_method
 */
final class FolioEntry extends HospitalityRecord
{
    public const array Categories = ['room', 'city_tax', 'extra', 'restaurant', 'other'];

    protected $table = 'hosp_folio_entries';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_amount' => 'decimal:2', 'amount' => 'decimal:2', 'posted_on' => 'date:Y-m-d', 'voided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Stay, $this>
     */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class, 'stay_id');
    }
}
