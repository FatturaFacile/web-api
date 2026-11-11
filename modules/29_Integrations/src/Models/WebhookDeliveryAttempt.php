<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HTTP attempt of a delivery: what the receiver answered (or why there was no answer) and how long it took.
 */
class WebhookDeliveryAttempt extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'webhook_delivery_id', 'attempt_number', 'succeeded', 'response_code', 'duration_ms', 'error', 'response_excerpt', 'created_at'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'attempt_number' => 'integer', 'response_code' => 'integer', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
