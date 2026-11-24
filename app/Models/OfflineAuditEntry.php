<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry of a device's local hash-chained audit queue, as received from the desktop or mobile client. The device
 * hashes are kept verbatim (the server never holds the device key, so it checks the links, not the HMAC); the same
 * action is also appended to the server's own tamper-evident chain (audit_log_id).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $device_id
 * @property string $origin
 * @property int $sequence_number
 * @property string $previous_hash
 * @property string $payload_hash
 * @property string $record_hash
 * @property string $occurred_at
 * @property string $entity_type
 * @property string|null $entity_id
 * @property string $action
 * @property string|null $payload
 * @property string|null $metadata
 * @property string|null $batch_id
 * @property string|null $audit_log_id
 * @property Carbon $received_at
 */
class OfflineAuditEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'received_at' => 'datetime',
        ];
    }
}
