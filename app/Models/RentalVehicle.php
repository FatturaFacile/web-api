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

    /**
     * Calculated grid columns as portable SQL: the renter and return date come from the active booking.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        $activeBooking = "FROM rental_bookings rb JOIN contacts c ON c.id = rb.client_id WHERE rb.rental_vehicle_id = rental_vehicles.id AND rb.status = 'active' AND rb.deleted_at IS NULL";

        return [
            'current_client' => "(SELECT COALESCE(NULLIF(c.company_name, ''), NULLIF(TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')), ''), '-') {$activeBooking} LIMIT 1)",
            'current_return_date' => "(SELECT MAX(rb.end_date) {$activeBooking})",
            'rental_status' => "CASE WHEN rental_vehicles.is_available THEN 'available' ELSE 'rented' END",
        ];
    }

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
