<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'address',
        'is_primary',
    ];

    /**
     * Calculated grid columns as portable SQL, so they filter, sort, group and list their values in the database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            // Booleans are cast to different texts by SQLite and PostgreSQL: a text state is the same on both.
            'primary_state' => "CASE WHEN warehouses.is_primary THEN 'primary' ELSE 'secondary' END",
        ];
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }
}
