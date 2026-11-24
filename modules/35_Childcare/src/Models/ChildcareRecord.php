<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the childcare models: tenant scoped, UUID v7 keys. Deliberately not Auditable: the audit ledger stores raw
 * payloads and must never receive data about children. Personal and health columns use encrypted casts.
 */
abstract class ChildcareRecord extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @return Factory<static>|null
     */
    protected static function newFactory()
    {
        $factoryName = 'Database\\Factories\\Childcare'.class_basename(static::class).'Factory';

        return class_exists($factoryName) ? $factoryName::new() : null;
    }
}
