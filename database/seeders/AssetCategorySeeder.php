<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if (app(TenantContext::class)->hasTenant() && ! $tenants->contains('id', app(TenantContext::class)->getTenantId())) {
            $tenants->push(app(TenantContext::class)->getTenant());
        }

        if ($tenants->isEmpty()) {
            return;
        }

        $categories = [
            [
                'code' => 'BUILDINGS',
                'name' => 'Industrial & Commercial Buildings',
                'asset_type' => 'real_estate',
                'depreciation_method' => 'straight_line',
                'useful_life_years' => 33,
                'statutory_rate_percent' => 3.00,
                'salvage_value_percent' => 10.00,
                'gl_asset_account_code' => '1610',
                'translations' => [
                    'it' => 'Fabbricati Industriali e Commerciali',
                    'en' => 'Industrial & Commercial Buildings',
                    'de' => 'Industrie- und Geschäftsgebäude',
                    'fr' => 'Bâtiments industriels et commerciaux',
                ],
            ],
            [
                'code' => 'MACHINERY',
                'name' => 'Plant, Heavy Machinery & CNC Equipment',
                'asset_type' => 'tangible',
                'depreciation_method' => 'straight_line',
                'useful_life_years' => 8,
                'statutory_rate_percent' => 12.50,
                'salvage_value_percent' => 5.00,
                'gl_asset_account_code' => '1620',
                'translations' => [
                    'it' => 'Impianti e Macchinari Industriali',
                    'en' => 'Plant, Heavy Machinery & CNC Equipment',
                    'de' => 'Technische Anlagen und Maschinen',
                    'fr' => 'Installations techniques et machines',
                ],
            ],
            [
                'code' => 'IT_SERVERS',
                'name' => 'Servers, Data Center & Network Infrastructure',
                'asset_type' => 'tangible',
                'depreciation_method' => 'double_declining_balance',
                'useful_life_years' => 5,
                'statutory_rate_percent' => 20.00,
                'salvage_value_percent' => 0.00,
                'gl_asset_account_code' => '1630',
                'translations' => [
                    'it' => 'Server, Data Center e Infrastruttura di Rete',
                    'en' => 'Servers, Data Center & Network Infrastructure',
                    'de' => 'Server, Rechenzentrum und Netzwerkinfrastruktur',
                    'fr' => 'Serveurs, centre de données et infrastructure réseau',
                ],
            ],
            [
                'code' => 'VEHICLES',
                'name' => 'Commercial Vehicles, Trucks & Fleet',
                'asset_type' => 'tangible',
                'depreciation_method' => 'units_of_production',
                'useful_life_years' => 5,
                'statutory_rate_percent' => 20.00,
                'salvage_value_percent' => 10.00,
                'gl_asset_account_code' => '1640',
                'translations' => [
                    'it' => 'Automezzi Commerciali e Flotta Aziendale',
                    'en' => 'Commercial Vehicles, Trucks & Fleet',
                    'de' => 'Nutzfahrzeuge, LKW und Fuhrpark',
                    'fr' => 'Véhicules utilitaires, camions et flotte',
                ],
            ],
            [
                'code' => 'FURNITURE',
                'name' => 'Office Furniture, Fixtures & Retail Shelving',
                'asset_type' => 'tangible',
                'depreciation_method' => 'straight_line',
                'useful_life_years' => 8,
                'statutory_rate_percent' => 12.00,
                'salvage_value_percent' => 0.00,
                'gl_asset_account_code' => '1660',
                'translations' => [
                    'it' => 'Mobili, Arredi e Scaffalature Retail',
                    'en' => 'Office Furniture, Fixtures & Retail Shelving',
                    'de' => 'Büromöbel, Einrichtungen und Verkaufsregale',
                    'fr' => 'Mobilier de bureau, agencements et rayonnages',
                ],
            ],
            [
                'code' => 'LICENSES',
                'name' => 'Enterprise Software Licenses & Patents',
                'asset_type' => 'intangible',
                'depreciation_method' => 'straight_line',
                'useful_life_years' => 3,
                'statutory_rate_percent' => 33.33,
                'salvage_value_percent' => 0.00,
                'gl_asset_account_code' => '1510',
                'translations' => [
                    'it' => 'Licenze Software Enterprise e Brevetti',
                    'en' => 'Enterprise Software Licenses & Patents',
                    'de' => 'Unternehmenssoftwarelizenzen und Patente',
                    'fr' => 'Licences logicielles d\'entreprise et brevets',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($categories as $cat) {
                $existingId = DB::table('eam_asset_categories')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $cat['code'])
                    ->value('id');

                DB::table('eam_asset_categories')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $cat['code'],
                    ],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $cat['name'],
                        'asset_type' => $cat['asset_type'],
                        'depreciation_method' => $cat['depreciation_method'],
                        'useful_life_years' => $cat['useful_life_years'],
                        'useful_life_months' => $cat['useful_life_years'] * 12,
                        'statutory_rate_percent' => $cat['statutory_rate_percent'],
                        'salvage_value_percent' => $cat['salvage_value_percent'],
                        'gl_asset_account_code' => $cat['gl_asset_account_code'],
                        'gl_accumulated_depreciation_code' => '1650',
                        'gl_depreciation_expense_code' => '6400',
                        'gl_impairment_account_code' => '6450',
                        'gl_revaluation_reserve_code' => '3300',
                        'gl_disposal_gain_loss_code' => '7500',
                        'gl_clearing_account_code' => '2150',
                        'is_depreciable' => true,
                        'is_active' => true,
                        'translations' => json_encode($cat['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
