<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationPackageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;

/**
 * Submission information package: the files of one document class, with a manifest (file, SHA-256, metadata) and, once
 * closed, a package hash and the time-stamp data of the provider. A closed package can no longer be updated or deleted.
 *
 * This prepares the technical basis of legal preservation; it is NOT a certified conservatore (AgID accreditation).
 *
 * @property string $id
 * @property string $status
 * @property string $document_class
 * @property ?string $manifest_json
 * @property ?string $package_hash
 * @property ?Carbon $closed_at
 * @property ?Carbon $retention_until
 * @property ?Carbon $period_from
 * @property ?Carbon $period_to
 * @property ?Carbon $timestamped_at
 * @property ?Carbon $created_at
 */
#[UseFactory(PreservationPackageFactory::class)]
class PreservationPackage extends Model
{
    /** @use HasFactory<PreservationPackageFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const string Open = 'open';

    public const string Closed = 'closed';

    protected $fillable = [
        'tenant_id', 'document_class', 'name', 'status', 'period_from', 'period_to', 'items_count', 'total_bytes',
        'retention_years', 'retention_source', 'retention_until', 'manifest_json', 'package_hash', 'closed_at', 'closed_by',
        'timestamp_provider', 'timestamp_token', 'timestamp_qualified', 'timestamped_at', 'created_by',
    ];

    /**
     * The manifest text is internal (it is exported as a file); the token is shown only through the resource.
     *
     * @var list<string>
     */
    protected $hidden = ['manifest_json'];

    protected static function booted(): void
    {
        static::updating(static function (self $package): void {
            if ($package->getOriginal('status') === self::Closed) {
                throw new PreservationImmutableException('A closed preservation package is immutable.');
            }
        });

        static::deleting(static function (self $package): void {
            if ($package->getOriginal('status') === self::Closed) {
                throw new PreservationImmutableException('A closed preservation package cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'retention_until' => 'date',
            'closed_at' => 'datetime',
            'timestamped_at' => 'datetime',
            'timestamp_qualified' => 'boolean',
            'items_count' => 'integer',
            'total_bytes' => 'integer',
            'retention_years' => 'integer',
        ];
    }

    /**
     * Whether the package is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === self::Closed;
    }

    /**
     * @return HasMany<PreservationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PreservationItem::class, 'package_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<PreservationVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(PreservationVerification::class, 'package_id')->orderByDesc('verified_at');
    }

    /**
     * @return HasOne<PreservationDisposal, $this>
     */
    public function disposal(): HasOne
    {
        return $this->hasOne(PreservationDisposal::class, 'package_id');
    }

    /**
     * @return HasOne<PreservationVerification, $this>
     */
    public function latestVerification(): HasOne
    {
        return $this->hasOne(PreservationVerification::class, 'package_id')->orderByDesc('verified_at');
    }
}
