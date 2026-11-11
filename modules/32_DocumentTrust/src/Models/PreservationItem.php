<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;

/**
 * One preserved file: a copy of the source document stored under the package, with its SHA-256 and metadata. An item
 * is never updated; it can be removed only while its package is open.
 *
 * @property string $id
 * @property string $package_id
 * @property string $sha256
 * @property string $storage_disk
 * @property string $storage_path
 * @property ?Carbon $document_date
 */
#[UseFactory(PreservationItemFactory::class)]
class PreservationItem extends Model
{
    /** @use HasFactory<PreservationItemFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'package_id', 'sequence', 'document_class', 'source_type', 'source_id', 'file_name', 'mime_type', 'size',
        'sha256', 'storage_disk', 'storage_path', 'document_type', 'document_date', 'document_number', 'counterpart_name',
        'counterpart_vat_number', 'own_vat_number', 'metadata',
    ];

    /**
     * The storage location is internal.
     *
     * @var list<string>
     */
    protected $hidden = ['storage_disk', 'storage_path'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new PreservationImmutableException('A preserved file cannot be changed.');
        });

        static::deleting(static function (self $item): void {
            if (PreservationPackage::withoutGlobalScopes()->whereKey($item->package_id)->where('status', PreservationPackage::Closed)->exists()) {
                throw new PreservationImmutableException('The files of a closed preservation package cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['document_date' => 'date', 'metadata' => 'array', 'size' => 'integer', 'sequence' => 'integer'];
    }

    /**
     * @return BelongsTo<PreservationPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PreservationPackage::class, 'package_id');
    }
}
