<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationAccessLogFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;

/**
 * Who touched the preservation archive and how (view, search, export, verify, close, settings). Append-only.
 *
 * @property string $action
 * @property Carbon $created_at
 */
#[UseFactory(PreservationAccessLogFactory::class)]
class PreservationAccessLog extends Model
{
    /** @use HasFactory<PreservationAccessLogFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const ?string UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'package_id', 'user_id', 'action', 'ip_address', 'details', 'created_at'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new PreservationImmutableException('Access records are append-only.');
        });

        static::deleting(static function (): void {
            throw new PreservationImmutableException('Access records are append-only.');
        });
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
