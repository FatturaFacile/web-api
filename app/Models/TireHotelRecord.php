<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TireHotelRecord extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'tire_hotel_records';

    protected $fillable = [
        'tenant_id',
        'vehicle_id',
        'season',
        'brand',
        'model',
        'size',
        'dot',
        'front_left_tread_mm',
        'front_right_tread_mm',
        'rear_left_tread_mm',
        'rear_right_tread_mm',
        'warehouse_shelf_location',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'front_left_tread_mm' => 'decimal:2',
            'front_right_tread_mm' => 'decimal:2',
            'rear_left_tread_mm' => 'decimal:2',
            'rear_right_tread_mm' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
