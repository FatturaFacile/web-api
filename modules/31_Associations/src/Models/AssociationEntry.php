<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the frequent operational rows (attendance, presences, sent notices, access log): tenant scoped, without soft
 * deletes and without one audit ledger entry per row.
 */
abstract class AssociationEntry extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * @return Factory<static>|null
     */
    protected static function newFactory()
    {
        $factoryName = 'Database\\Factories\\'.class_basename(static::class).'Factory';

        return class_exists($factoryName) ? $factoryName::new() : null;
    }
}
