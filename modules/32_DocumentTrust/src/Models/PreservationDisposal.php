<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationDisposalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;

/**
 * Irrevocable record of a disposal: what was disposed of (package, class, files with their hashes), who proposed,
 * approved and executed it, when, and the manifest with its SHA-256 as proof. Append-only; the files were deleted
 * from the disk, the manifest and this record stay.
 *
 * @property string $package_id
 * @property ?Carbon $executed_at
 * @property ?Carbon $retention_until
 * @property ?Carbon $expiry_date
 * @property array<int, array<string, mixed>> $files
 */
#[UseFactory(PreservationDisposalFactory::class)]
class PreservationDisposal extends Model
{
    /** @use HasFactory<PreservationDisposalFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'package_id', 'proposal_id', 'document_class', 'package_name', 'items_count', 'total_bytes', 'retention_until',
        'expiry_date', 'manifest_sha256', 'manifest_json', 'files', 'proposed_by', 'approved_by', 'executed_by', 'executed_at',
    ];

    /**
     * The manifest is returned through the package export, not here.
     *
     * @var list<string>
     */
    protected $hidden = ['manifest_json'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new PreservationImmutableException('A disposal record is irrevocable.');
        });

        static::deleting(static function (): void {
            throw new PreservationImmutableException('A disposal record is irrevocable.');
        });
    }

    protected function casts(): array
    {
        return ['files' => 'array', 'retention_until' => 'date', 'expiry_date' => 'date', 'executed_at' => 'datetime', 'items_count' => 'integer', 'total_bytes' => 'integer'];
    }
}
