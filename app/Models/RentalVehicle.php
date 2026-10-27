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

class RentalVehicle extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'rental_vehicles';

    protected $fillable = [
        'tenant_id',
        'vehicle_id',
        'category',
        'daily_rate',
        'fuel_type',
        'transmission',
        'is_available',
        'current_mileage',
    ];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'is_available' => 'boolean',
            'current_mileage' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * @return HasMany<RentalBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(RentalBooking::class, 'rental_vehicle_id');
    }
}
