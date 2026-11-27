<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Deduplication ledger of the gateway webhooks: the unique (tenant, webhook_event_id) makes a replayed delivery a no-op.
 *
 * @property string $webhook_event_id
 * @property string|null $event_type
 * @property string|null $transmission_id
 * @property string $payload_hash
 * @property array<string, mixed>|null $payload
 * @property string $outcome
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
final class EbillWebhookEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'ebill_webhook_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
