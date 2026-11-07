<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the construction trades models: tenant scoped, UUID v7 keys. Price lists and work cycles may be generic
 * (no tenant): shared by every company and read-only for them.
 */
abstract class TradesRecord extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    public function isGeneric(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * @return Factory<static>|null
     */
    protected static function newFactory()
    {
        $factoryName = 'Database\\Factories\\Trades'.class_basename(static::class).'Factory';

        return class_exists($factoryName) ? $factoryName::new() : null;
    }
}
