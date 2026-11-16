<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkSite extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'latitude', 'longitude', 'radius_meters', 'is_active'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Great-circle distance in metres (haversine). */
    public function distanceTo(float $lat, float $lng): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function contains(float $lat, float $lng): bool
    {
        return $this->distanceTo($lat, $lng) <= $this->radius_meters;
    }
}
