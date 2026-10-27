<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamageInspection extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'damage_inspections';

    protected $fillable = [
        'tenant_id',
        'rental_booking_id',
        'type',
        'mileage',
        'fuel_level',
        'damage_markers',
        'photos',
        'inspected_by_user_id',
        'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
            'damage_markers' => 'array',
            'photos' => 'array',
            'inspected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RentalBooking, $this>
     */
    public function rentalBooking(): BelongsTo
    {
        return $this->belongsTo(RentalBooking::class, 'rental_booking_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }
}
