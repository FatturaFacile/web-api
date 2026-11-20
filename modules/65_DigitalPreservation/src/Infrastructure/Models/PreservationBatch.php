<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\DigitalPreservation\Domain\Enums\BatchStatus;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationImmutableException;

/**
 * A preservation batch: the PdV while open and sealed, the PdA once time-stamped. After sealing, the evidence fields
 * (hashes, Merkle root, paths, retention date) can no longer change and the batch cannot be deleted before its
 * retention date.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $batch_number
 * @property JurisdictionMode $jurisdiction
 * @property BatchStatus $status
 * @property DocumentCategory $document_category
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property int $item_count
 * @property int $total_size_bytes
 * @property ?string $pdv_xml_path
 * @property ?string $pda_archive_path
 * @property ?string $pdv_sha256
 * @property ?string $pda_sha256
 * @property ?string $merkle_root_sha256
 * @property ?string $previous_batch_sha256
 * @property ?string $rfc3161_token_path
 * @property ?Carbon $rfc3161_gen_time
 * @property ?string $rfc3161_provider
 * @property bool $rfc3161_qualified
 * @property ?string $external_conservator_id
 * @property ?string $rdv_receipt_path
 * @property ?Carbon $retention_until
 * @property ?string $sealed_by
 * @property ?Carbon $sealed_at
 * @property ?Carbon $dispatched_at
 */
class PreservationBatch extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * Fields that may still change after sealing: the lifecycle of the time stamp, the dispatch and the receipt.
     *
     * @var list<string>
     */
    private const array LifecycleFields = [
        'status', 'pda_archive_path', 'pda_sha256', 'rfc3161_token_path', 'rfc3161_gen_time', 'rfc3161_provider',
        'rfc3161_qualified', 'external_conservator_id', 'rdv_receipt_path', 'dispatched_at', 'updated_at',
    ];

    protected $fillable = [
        'tenant_id', 'batch_number', 'jurisdiction', 'status', 'document_category', 'period_from', 'period_to', 'item_count',
        'total_size_bytes', 'pdv_xml_path', 'pda_archive_path', 'pdv_sha256', 'pda_sha256', 'merkle_root_sha256',
        'previous_batch_sha256', 'rfc3161_token_path', 'rfc3161_gen_time', 'rfc3161_provider', 'rfc3161_qualified',
        'external_conservator_id', 'rdv_receipt_path', 'retention_until', 'sealed_by', 'sealed_at', 'dispatched_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $batch): void {
            $original = BatchStatus::tryFrom((string) $batch->getRawOriginal('status')) ?? BatchStatus::Draft;

            if (! $original->isFrozen()) {
                return;
            }

            // Sealing itself (draft to sealed) is the last write of the evidence fields; afterwards they are locked.
            $forbidden = array_diff(array_keys($batch->getDirty()), self::LifecycleFields);

            if ($forbidden !== []) {
                throw new PreservationImmutableException('A sealed preservation batch is immutable: '.implode(', ', $forbidden).'.');
            }
        });

        static::deleting(static function (self $batch): void {
            if (! $batch->status->isFrozen()) {
                return;
            }

            if ($batch->retention_until === null || $batch->retention_until->endOfDay()->isFuture()) {
                throw new PreservationImmutableException('A preservation batch cannot be deleted before its retention date.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'jurisdiction' => JurisdictionMode::class,
            'status' => BatchStatus::class,
            'document_category' => DocumentCategory::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'item_count' => 'integer',
            'total_size_bytes' => 'integer',
            'rfc3161_gen_time' => 'datetime',
            'rfc3161_qualified' => 'boolean',
            'retention_until' => 'date',
            'sealed_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PreservationBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PreservationBatchItem::class, 'preservation_batch_id')->orderBy('sequence');
    }
}
