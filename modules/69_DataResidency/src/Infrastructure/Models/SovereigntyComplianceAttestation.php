<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * An immutable, signed snapshot of the physical topology and the key status at the time of issue.
 *
 * @property string $id
 * @property string|null $legal_entity_id
 * @property string $certificate_number
 * @property string $jurisdiction_zone
 * @property array<string, mixed> $topology_snapshot_json
 * @property array<string, mixed> $byok_snapshot_json
 * @property list<string> $compliance_frameworks_json
 * @property string $sha256_payload_hash
 * @property string $digital_signature
 * @property string $signer_key_fingerprint
 * @property Carbon $issued_at
 * @property Carbon $valid_until
 */
final class SovereigntyComplianceAttestation extends Model
{
    use BelongsToTenant;

    protected $table = 'core_sovereignty_attestations';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(static function (self $record): void {
            if (empty($record->id)) {
                $record->id = (string) Str::uuid7();
            }
        });
        self::updating(static fn () => throw new LogicException('SovereigntyComplianceAttestation records are append-only and cannot be updated.'));
        self::deleting(static fn () => throw new LogicException('SovereigntyComplianceAttestation records are append-only and cannot be deleted.'));
    }

    public function delete(): ?bool
    {
        throw new LogicException('SovereigntyComplianceAttestation records are append-only and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new LogicException('SovereigntyComplianceAttestation records are append-only and cannot be deleted.');
    }

    protected function casts(): array
    {
        return [
            'topology_snapshot_json' => 'array',
            'byok_snapshot_json' => 'array',
            'compliance_frameworks_json' => 'array',
            'issued_at' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }
}
