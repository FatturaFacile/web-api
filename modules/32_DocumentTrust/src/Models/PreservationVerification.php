<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationVerificationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;

/**
 * Result of one integrity check of a closed package. Append-only.
 *
 * @property string $result
 * @property array<int, array{item_id: ?string, reason: string}>|null $failures
 * @property Carbon $verified_at
 */
#[UseFactory(PreservationVerificationFactory::class)]
class PreservationVerification extends Model
{
    /** @use HasFactory<PreservationVerificationFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'package_id', 'result', 'trigger', 'manifest_ok', 'checked_items', 'failed_items', 'failures', 'verified_by', 'verified_at'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new PreservationImmutableException('Verification records are append-only.');
        });

        static::deleting(static function (): void {
            throw new PreservationImmutableException('Verification records are append-only.');
        });
    }

    protected function casts(): array
    {
        return ['manifest_ok' => 'boolean', 'failures' => 'array', 'verified_at' => 'datetime', 'checked_items' => 'integer', 'failed_items' => 'integer'];
    }
}
