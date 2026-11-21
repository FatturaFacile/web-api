<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\SovereignServiceType;
use Modules\DataResidency\Domain\ValueObjects\AdequacyRegistry;
use Modules\DataResidency\Infrastructure\Models\CrossBorderTransferRule;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;

/**
 * The sovereign infrastructure catalogue (Swiss sites Zurich, Geneva, Lugano/Ticino; Italian sites Milan, Turin, Rome) and
 * the statutory adequacy baseline of the cross-border rules (Swiss Federal Council list, EU Commission decisions). Both
 * are global data shared by every tenant. Idempotent.
 */
class SovereignRegionNodeSeeder extends Seeder
{
    /** Site code => [country, canton or region, city, cloud region, latitude, longitude]. */
    private const array SITES = [
        'ch-zrh' => ['CH', 'ZH', 'Zurich', 'eu-central-2', 47.3768866, 8.5416940],
        'ch-gva' => ['CH', 'GE', 'Geneva', 'switzerlandwest', 46.2043907, 6.1431577],
        'ch-lug' => ['CH', 'TI', 'Lugano', 'europe-west6', 46.0036778, 8.9510711],
        'it-mil' => ['IT', 'Lombardia', 'Milan', 'eu-south-1', 45.4642035, 9.1899817],
        'it-tor' => ['IT', 'Piemonte', 'Turin', 'italynorth', 45.0703393, 7.6869005],
        'it-rom' => ['IT', 'Lazio', 'Rome', 'europe-west8', 41.9027835, 12.4963655],
    ];

    /** Service type => [node code template, label]. */
    private const array SERVICES = [
        'postgres_primary' => ['{site}-01', 'PostgreSQL primary'],
        'postgres_replica' => ['{site}-02', 'PostgreSQL replica'],
        's3_dms' => ['{site}-s3-01', 'S3 document storage'],
        'pgvector' => ['{site}-vec-01', 'pgvector store'],
        'ai_inference' => ['{site}-ai-01', 'AI/OCR inference'],
        'backup_vault' => ['{site}-bkp-01', 'Backup vault'],
        'queue_broker' => ['{site}-q-01', 'Queue broker'],
    ];

    public function run(): void
    {
        app(TenantContext::class)->runAsGlobal(function (): void {
            $this->nodes();
            $this->rules();
        });
    }

    private function nodes(): void
    {
        foreach (self::SITES as $site => [$country, $region, $city, $cloudRegion, $latitude, $longitude]) {
            $certifications = $country === 'CH'
                ? ['ISO-27001', 'FINMA-2018-3', 'SOC2-Type2']
                : ['ISO-27001', 'ACN-QC2', 'SOC2-Type2'];

            foreach (self::SERVICES as $type => [$template]) {
                $code = str_replace('{site}', $site, $template);

                $node = SovereignRegionNode::query()->withoutGlobalScopes()->where('node_code', $code)->first() ?? new SovereignRegionNode;

                if (! $node->exists) {
                    $node->setEndpoint([
                        'connection' => 'pgsql_'.str_replace('-', '_', $site),
                        'endpoint' => "https://{$code}.fatturafacile.eu",
                        'region' => $cloudRegion,
                        'bucket' => "ff-{$code}",
                    ]);
                }

                $node->forceFill([
                    'tenant_id' => null,
                    'node_code' => $code,
                    'service_type' => SovereignServiceType::from($type),
                    'country_iso2' => $country,
                    'canton_or_region' => $region,
                    'city' => $city,
                    'provider_name' => $country === 'CH' ? 'FatturaFacile Sovereign Cloud CH' : 'FatturaFacile Sovereign Cloud IT',
                    'certifications_json' => $certifications,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_active' => true,
                ]);
                $node->saveQuietly();
            }
        }
    }

    private function rules(): void
    {
        foreach (JurisdictionZone::cases() as $zone) {
            foreach (AdequacyRegistry::countries() as $iso2 => $name) {
                $rule = CrossBorderTransferRule::query()->withoutGlobalScopes()->whereNull('tenant_id')
                    ->where('source_jurisdiction', $zone->value)->where('destination_country_iso2', $iso2)->first() ?? new CrossBorderTransferRule;

                $rule->forceFill([
                    'tenant_id' => null,
                    'source_jurisdiction' => $zone->value,
                    'destination_country_iso2' => $iso2,
                    'destination_country_name' => $name,
                    'has_ch_adequacy' => AdequacyRegistry::hasChAdequacy($iso2),
                    'has_eu_adequacy' => AdequacyRegistry::hasEuAdequacy($iso2),
                    'decision_status' => AdequacyRegistry::decisionFor($zone, $iso2),
                    'legal_basis_reference' => AdequacyRegistry::legalBasis($zone, $iso2),
                ]);
                $rule->saveQuietly();
            }
        }
    }
}
