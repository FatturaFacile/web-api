<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationImmutableException;

/**
 * One preserved file of a batch. Immutable as soon as the batch is no longer a draft, and not deletable before its
 * retention date.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $preservation_batch_id
 * @property int $sequence
 * @property string $preservable_type
 * @property string $preservable_id
 * @property string $document_uuid
 * @property string $document_type
 * @property ?string $document_number
 * @property ?Carbon $document_date
 * @property ?string $counterparty_name
 * @property ?string $counterparty_tax_id
 * @property string $original_filename
 * @property string $archived_filename
 * @property string $archive_path
 * @property string $mime_type
 * @property bool $is_pdfa_compliant
 * @property ?string $pdfa_conformance_level
 * @property int $file_size_bytes
 * @property string $sha256_hash
 * @property array<string, mixed>|null $metadata_json
 * @property ?Carbon $retention_until
 */
class PreservationBatchItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'preservation_batch_id', 'sequence', 'preservable_type', 'preservable_id', 'document_uuid', 'document_type',
        'document_number', 'document_date', 'counterparty_name', 'counterparty_tax_id', 'original_filename', 'archived_filename',
        'archive_path', 'mime_type', 'is_pdfa_compliant', 'pdfa_conformance_level', 'file_size_bytes', 'sha256_hash',
        'metadata_json', 'retention_until',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $item): void {
            if (self::batchIsFrozen($item)) {
                throw new PreservationImmutableException('A preserved item is immutable once its batch is sealed.');
            }
        });

        static::deleting(static function (self $item): void {
            $locked = $item->retention_until === null || $item->retention_until->endOfDay()->isFuture();

            if ($locked && self::batchIsFrozen($item)) {
                throw new PreservationImmutableException('A preserved item cannot be deleted before its retention date.');
            }
        });
    }

    private static function batchIsFrozen(self $item): bool
    {
        $status = PreservationBatch::withoutGlobalScopes()->whereKey($item->preservation_batch_id)->value('status');

        return $status !== null && $status !== 'draft';
    }

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'is_pdfa_compliant' => 'boolean',
            'file_size_bytes' => 'integer',
            'sequence' => 'integer',
            'metadata_json' => 'array',
            'retention_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PreservationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PreservationBatch::class, 'preservation_batch_id');
    }
}
