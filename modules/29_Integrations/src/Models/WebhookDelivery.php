<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event owed to one endpoint. The idempotency key never changes: automatic retries and manual redeliveries send
 * the same key, so the receiver can recognise a delivery it has already processed.
 */
#[UseFactory(WebhookDeliveryFactory::class)]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const string StatusPending = 'pending';

    public const string StatusRetrying = 'retrying';

    public const string StatusSucceeded = 'succeeded';

    /**
     * Dead letter: out of attempts, or its endpoint was disabled. Kept in the log and redelivered by hand.
     */
    public const string StatusDead = 'dead';

    protected $fillable = [
        'tenant_id', 'webhook_endpoint_id', 'event_id', 'event_name', 'idempotency_key', 'status', 'attempts',
        'next_attempt_at', 'claimed_at', 'payload', 'payload_version', 'is_test', 'dedupe_hash', 'last_response_code',
        'last_error', 'last_duration_ms', 'delivered_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['dedupe_hash'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'is_test' => 'boolean',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_response_code' => 'integer',
            'last_duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * @return HasMany<WebhookDeliveryAttempt, $this>
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class)->orderBy('attempt_number');
    }
}
