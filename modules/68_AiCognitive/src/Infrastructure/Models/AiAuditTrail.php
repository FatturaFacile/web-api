<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only, hash chained record of every AI inference, suggestion and human decision (GeBüV / Olico, EU AI Act
 * transparency). Each row carries the hash of the previous row of the tenant, so a removed or edited row breaks the chain.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $sequence
 * @property string $event_type
 * @property string|null $actor_user_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $confidence
 * @property array<string, mixed>|null $payload_json
 * @property string|null $previous_hash
 * @property string $hash
 * @property Carbon|null $created_at
 */
final class AiAuditTrail extends Model
{
    use BelongsToTenant, HasUuidV7, ProtectsRecords;

    protected $table = 'ai_audit_trails';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'confidence' => 'decimal:2',
            'payload_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        return $operation === 'create' ? null : 'The AI audit trail is append-only: a record cannot be changed or deleted.';
    }
}
