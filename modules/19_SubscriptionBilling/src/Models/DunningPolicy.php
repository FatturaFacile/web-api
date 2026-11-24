<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The dunning policy of a company: escalation days and collection retries.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $policy_name
 * @property bool $is_default
 * @property int $soft_reminder_days
 * @property int $formal_notice_days
 * @property int $suspension_days
 * @property int $termination_days
 * @property int $max_collection_retries
 * @property int $retry_interval_days
 * @property Carbon|null $created_at
 */
final class DunningPolicy extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_dunning_policies';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'soft_reminder_days' => 'integer',
            'formal_notice_days' => 'integer',
            'suspension_days' => 'integer',
            'termination_days' => 'integer',
            'max_collection_retries' => 'integer',
            'retry_interval_days' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\DunningPolicyFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }
}
