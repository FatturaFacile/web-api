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

class Vehicle extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'vehicles';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'license_plate',
        'vin_chassis',
        'make',
        'model',
        'year',
        'registration_date',
        'mileage_km',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'registration_date' => 'date',
            'mileage_km' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    /**
     * @return HasMany<TireHotelRecord, $this>
     */
    public function tireHotelRecords(): HasMany
    {
        return $this->hasMany(TireHotelRecord::class, 'vehicle_id');
    }

    /**
     * @return HasMany<VehicleInspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class, 'vehicle_id');
    }
}
