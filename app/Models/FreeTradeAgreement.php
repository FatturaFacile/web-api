<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksAuditUsers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Global (non tenant-scoped) free trade agreement (PEM / CH-EU, EU-UK, ...) with origin declaration templates.
 */
class FreeTradeAgreement extends Model
{
    use HasUuids, TracksAuditUsers;

    protected $fillable = [
        'code',
        'name',
        'partner_countries',
        'value_threshold_eur',
        'value_threshold_chf',
        'proof_types',
        'supports_approved_exporter',
        'supports_rex',
        'declaration_templates',
        'declaration_templates_no_auth',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'partner_countries' => 'array',
            'value_threshold_eur' => 'decimal:2',
            'value_threshold_chf' => 'decimal:2',
            'proof_types' => 'array',
            'supports_approved_exporter' => 'boolean',
            'supports_rex' => 'boolean',
            'declaration_templates' => 'array',
            'declaration_templates_no_auth' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<FreeTradeAgreement>  $query
     * @return Builder<FreeTradeAgreement>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * True when both ISO country codes are covered by this agreement.
     */
    public function coversCountries(string $exporterCountry, string $importerCountry): bool
    {
        $countries = (array) $this->partner_countries;

        return $exporterCountry !== $importerCountry
            && in_array(strtoupper($exporterCountry), $countries, true)
            && in_array(strtoupper($importerCountry), $countries, true);
    }
}
