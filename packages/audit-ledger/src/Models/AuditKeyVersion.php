<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Database\Eloquent\Model;

/**
 * The activation of a key version for a tenant (RotateAuditKeyCommand). Append only: the highest version is the one in
 * force, older versions only stay to document when each one started.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $key_version
 */
final class AuditKeyVersion extends Model
{
    use ProtectsRecords;

    protected $table = 'audit_key_versions';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function recordProtectionViolation(string $operation): ?string
    {
        return $operation === 'create' ? null : 'Audit key version activations are append only.';
    }

    protected function casts(): array
    {
        return ['key_version' => 'integer', 'activated_at' => 'datetime'];
    }
}
