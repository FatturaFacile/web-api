<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The Merkle root that seals the events recorded in one audit period (day or month) of a tenant. Sealed once, never changed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $period_type day|month
 * @property string $period_start Y-m-d
 * @property string $period_end Y-m-d
 * @property string $merkle_root
 * @property int $event_count
 * @property string $sealed_at
 */
final class MerkleAuditAnchor extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use ProtectsRecords;

    protected $table = 'es_merkle_audit_anchors';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['event_count' => 'integer'];
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        return $operation === 'create' ? null : 'A sealed Merkle audit anchor cannot be updated or deleted.';
    }
}
