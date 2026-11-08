<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An open (or closed) dunning case of an overdue subscription invoice: escalation level, collection retries and the
 * reminders sent through the payment reminders of the invoicing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subscription_id
 * @property string $invoice_id
 * @property int $dunning_level
 * @property string $status
 * @property string $action_taken
 * @property int $retry_count
 * @property Carbon|null $next_retry_at
 * @property string|null $last_failure_reason
 * @property Carbon|null $last_action_at
 * @property int $days_overdue
 * @property array<int, array<string, mixed>>|null $communications_log
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 */
final class DunningRecord extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_dunning_records';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'dunning_level' => 'integer',
            'communications_log' => 'array',
            'resolved_at' => 'datetime',
            'retry_count' => 'integer',
            'next_retry_at' => 'datetime',
            'last_action_at' => 'datetime',
            'days_overdue' => 'integer',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\DunningRecordFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
