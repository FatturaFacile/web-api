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

    protected $fillable = ['tenant_id', 'retention_years', 'retention_confirmed_at', 'retention_confirmed_by', 'notes', 'tsa_url', 'tsa_username', 'tsa_password', 'tsa_provider_name', 'tsa_declared_qualified'];

    /**
     * The TSA credentials are never returned.
     *
     * @var list<string>
     */
    protected $hidden = ['tsa_username', 'tsa_password'];

    protected function casts(): array
    {
        return ['retention_years' => 'array', 'retention_confirmed_at' => 'datetime', 'tsa_username' => 'encrypted', 'tsa_password' => 'encrypted', 'tsa_declared_qualified' => 'boolean'];
    }
}
