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
     * Same resolution as the enterprise base model: `#[UseFactory]` first, then the factory named after the basename, only
     * when it builds this very model (`Attendance` exists in more than one module).
     *
     * @return Factory<static>|null
     */
    protected static function newFactory()
    {
        $declared = static::getUseFactoryAttribute();

        if ($declared !== null) {
            return $declared;
        }

        $factoryName = 'Database\\Factories\\'.class_basename(static::class).'Factory';

        if (! class_exists($factoryName)) {
            return null;
        }

        /** @var Factory<static> $factory */
        $factory = $factoryName::new();

        return $factory->modelName() === static::class ? $factory : null;
    }
}
