<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the customs models: tenant scoped, UUID v7 keys.
 */
abstract class CustomsRecord extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Generic rows (no tenant) are shared by every company and read-only for them.
     */
    public function isGeneric(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * @return Factory<static>|null
     */
    protected static function newFactory()
    {
        $factoryName = 'Database\\Factories\\'.class_basename(static::class).'Factory';

        return class_exists($factoryName) ? $factoryName::new() : null;
    }
}
