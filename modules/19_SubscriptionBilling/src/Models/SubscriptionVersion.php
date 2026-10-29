<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SubscriptionVersion extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_subscription_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'effective_date' => 'date',
            'mrr_before' => 'decimal:2',
            'mrr_after' => 'decimal:2',
            'mrr_delta' => 'decimal:2',
            'proration_amount' => 'decimal:2',
            'snapshot_payload' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\SubscriptionVersionFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
