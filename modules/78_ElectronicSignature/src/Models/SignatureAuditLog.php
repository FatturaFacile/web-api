<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry of the append-only audit trail of an envelope. Each entry carries the hash of the previous one, so a changed,
 * removed or inserted entry breaks the chain (see SignatureAuditService::verifyChain). The model refuses updates and deletes.
 *
 * @property string $id
 * @property string $envelope_id
 * @property string|null $recipient_id
 * @property int $sequence
 * @property string $event
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property string|null $document_sha256
 * @property string|null $receipt
 * @property string|null $timestamp_token
 * @property string $previous_hash
 * @property string $entry_hash
 * @property Carbon $occurred_at
 */
final class SignatureAuditLog extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'signature_audit_logs';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('The signature audit trail is append-only: an entry cannot be changed.');
        });

        self::deleting(static function (): never {
            throw new LogicException('The signature audit trail is append-only: an entry cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sequence' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SignatureEnvelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SignatureEnvelope::class, 'envelope_id');
    }
}
