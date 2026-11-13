<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EcommerceChannel extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /**
     * Secrets never leave the model through serialisation.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials', 'webhook_secret'];

    protected $fillable = [
        'tenant_id',
        'name',
        'driver',
        'api_url',
        'credentials',
        'webhook_secret',
        'is_active',
        'sync_stock_enabled',
        'sync_orders_enabled',
        'last_sync_at',
        'settings',
        'last_sync_summary',
        'at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_sync_at' => 'datetime',
            'settings' => 'array',
            'last_sync_summary' => 'array',
            'sync_orders_enabled' => 'boolean',
            'sync_stock_enabled' => 'boolean',
            'webhook_secret' => 'encrypted',
        ];
    }

    /**
     * Calculated grid columns as portable SQL, so they filter, sort, group and list their values in the database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        $table = $this->getTable();

        return [
            'status' => "CASE WHEN $table.is_active THEN 'active' ELSE 'inactive' END",
        ];
    }

    /**
     * @return HasMany<EcommerceMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(EcommerceMapping::class);
    }
}
