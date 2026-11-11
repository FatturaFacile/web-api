<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PreservationSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Retention periods of the tenant, in years, per document class. A class with no entry falls back to the suggested
 * default, which is to be confirmed by the accountant; `retention_confirmed_at` records that the tenant did.
 *
 * @property array<string, int>|null $retention_years
 * @property Carbon|null $retention_confirmed_at
 */
#[UseFactory(PreservationSettingFactory::class)]
class PreservationSetting extends Model
{
    /** @use HasFactory<PreservationSettingFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'retention_years', 'retention_confirmed_at', 'retention_confirmed_by', 'notes'];

    protected function casts(): array
    {
        return ['retention_years' => 'array', 'retention_confirmed_at' => 'datetime'];
    }
}
