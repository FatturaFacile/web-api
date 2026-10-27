<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentalBooking extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'rental_bookings';

    protected $fillable = [
        'tenant_id',
        'rental_vehicle_id',
        'client_id',
        'start_date',
        'end_date',
        'pickup_location',
        'return_location',
        'total_estimated',
        'deposit_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'total_estimated' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<RentalVehicle, $this>
     */
    public function rentalVehicle(): BelongsTo
    {
        return $this->belongsTo(RentalVehicle::class, 'rental_vehicle_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    /**
     * @return HasMany<DamageInspection, $this>
     */
    public function damageInspections(): HasMany
    {
        return $this->hasMany(DamageInspection::class, 'rental_booking_id');
    }
}
