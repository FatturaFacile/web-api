<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\DigitalPreservation\Domain\Enums\AutoSealFrequency;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;

/**
 * Per-tenant preservation settings. Credentials of the conservator and of the time-stamp authority are encrypted and
 * never serialized.
 *
 * @property string $id
 * @property string $tenant_id
 * @property JurisdictionMode $jurisdiction
 * @property PreservationProvider $provider
 * @property array<string, mixed>|null $credentials
 * @property ?string $preservation_officer_name
 * @property ?string $preservation_officer_tax_code
 * @property ?string $tsa_url
 * @property array<string, mixed>|null $tsa_credentials
 * @property AutoSealFrequency $auto_seal_frequency
 * @property int $default_retention_years
 * @property bool $is_active
 */
class PreservationConfiguration extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'jurisdiction', 'provider', 'credentials', 'preservation_officer_name', 'preservation_officer_tax_code',
        'tsa_url', 'tsa_credentials', 'auto_seal_frequency', 'default_retention_years', 'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials', 'tsa_credentials'];

    protected function casts(): array
    {
        return [
            'jurisdiction' => JurisdictionMode::class,
            'provider' => PreservationProvider::class,
            'auto_seal_frequency' => AutoSealFrequency::class,
            'credentials' => 'encrypted:array',
            'tsa_credentials' => 'encrypted:array',
            'default_retention_years' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
