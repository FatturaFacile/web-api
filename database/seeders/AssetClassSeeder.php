<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetClassSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $classes = [
            [
                'code' => 'CLS_PRODUCTION_CNC',
                'name' => 'CNC & Automated Manufacturing Systems',
                'criticality' => 'mission_critical',
                'interval' => 90,
                'custodian' => true,
                'calibration' => true,
                'translations' => [
                    'it' => 'Sistemi CNC e Produzione Automatizzata',
                    'en' => 'CNC & Automated Manufacturing Systems',
                    'de' => 'CNC- und automatisierte Fertigungssysteme',
                    'fr' => 'Systèmes CNC et fabrication automatisée',
                ],
            ],
            [
                'code' => 'CLS_HEAVY_EARTHMOVING',
                'name' => 'Heavy Construction & Earthmoving Equipment',
                'criticality' => 'high',
                'interval' => 60,
                'custodian' => true,
                'calibration' => false,
                'translations' => [
                    'it' => 'Macchine Movimento Terra e Cantiere Pesante',
                    'en' => 'Heavy Construction & Earthmoving Equipment',
                    'de' => 'Schwere Bau- und Erdbewegungsmaschinen',
                    'fr' => 'Engins lourds de chantier et terrassement',
                ],
            ],
            [
                'code' => 'CLS_DATACENTER_INFRA',
                'name' => 'Enterprise Server Racks & Storage Arrays',
                'criticality' => 'mission_critical',
                'interval' => 180,
                'custodian' => false,
                'calibration' => false,
                'translations' => [
                    'it' => 'Rack Server Enterprise e Storage Array',
                    'en' => 'Enterprise Server Racks & Storage Arrays',
                    'de' => 'Enterprise-Server-Racks und Storage-Arrays',
                    'fr' => 'Baies de serveurs et stockage d\'entreprise',
                ],
            ],
            [
                'code' => 'CLS_FLEET_COMMERCIAL',
                'name' => 'Commercial Delivery & Service Vehicles',
                'criticality' => 'high',
                'interval' => 120,
                'custodian' => true,
                'calibration' => false,
                'translations' => [
                    'it' => 'Veicoli Commerciali e Furgoni Assistenza',
                    'en' => 'Commercial Delivery & Service Vehicles',
                    'de' => 'Gewerbliche Liefer- und Servicefahrzeuge',
                    'fr' => 'Véhicules commerciaux de livraison et service',
                ],
            ],
            [
                'code' => 'CLS_FACILITY_BUILDING',
                'name' => 'Corporate Real Estate & Industrial Plants',
                'criticality' => 'medium',
                'interval' => 365,
                'custodian' => false,
                'calibration' => false,
                'translations' => [
                    'it' => 'Immobili Aziendali e Stabilimenti Industriali',
                    'en' => 'Corporate Real Estate & Industrial Plants',
                    'de' => 'Unternehmensimmobilien und Industrieanlagen',
                    'fr' => 'Immobilier d\'entreprise et usines industrielles',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($classes as $cls) {
                $existingId = DB::table('eam_asset_classes')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $cls['code'])
                    ->value('id');

                DB::table('eam_asset_classes')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $cls['code'],
                    ],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'name' => $cls['name'],
                        'criticality' => $cls['criticality'],
                        'default_maintenance_interval_days' => $cls['interval'],
                        'requires_custodian' => $cls['custodian'],
                        'requires_calibration' => $cls['calibration'],
                        'is_active' => true,
                        'translations' => json_encode($cls['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
