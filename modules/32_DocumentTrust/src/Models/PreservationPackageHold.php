<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationPackageHoldFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Legal hold on a closed package. The package itself is immutable, so the hold is a separate record: while one is
 * active (not released) the package cannot be disposed of.
 *
 * @property string $package_id
 * @property ?Carbon $released_at
 */
#[UseFactory(PreservationPackageHoldFactory::class)]
class PreservationPackageHold extends Model
{
    /** @use HasFactory<PreservationPackageHoldFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'package_id', 'reason', 'placed_by', 'placed_at', 'released_by', 'released_at'];

    protected function casts(): array
    {
        return ['placed_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
