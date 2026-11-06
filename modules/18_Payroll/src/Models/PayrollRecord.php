<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the payroll models. It does not extend BaseEnterpriseModel on purpose: its Auditable trait writes the casted
 * original attributes to the audit ledger, which would put decrypted identification numbers and salaries in an
 * immutable log. Models without personal data add Auditable themselves; the rest of the trail is payroll_run_events.
 */
abstract class PayrollRecord extends Model
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
