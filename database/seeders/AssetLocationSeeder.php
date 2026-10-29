<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetLocationSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $locations = [
            [
                'code' => 'LOC-MIL-HQ',
                'name' => 'Milan Corporate Headquarters',
                'location_type' => 'headquarters',
                'address' => 'Via Monte Napoleone 10',
                'city' => 'Milano',
                'country' => 'IT',
                'translations' => [
                    'it' => 'Sede Direzionale Milano',
                    'en' => 'Milan Corporate Headquarters',
                    'de' => 'Unternehmenszentrale Mailand',
                    'fr' => 'Siège Social Milan',
                ],
            ],
            [
                'code' => 'LOC-BRE-PLT',
                'name' => 'Brescia Manufacturing Plant & Robotics Center',
                'location_type' => 'plant',
                'address' => 'Viale dell\'Industria 42',
                'city' => 'Brescia',
                'country' => 'IT',
                'translations' => [
                    'it' => 'Stabilimento Produttivo e Centro Robotico Brescia',
                    'en' => 'Brescia Manufacturing Plant & Robotics Center',
                    'de' => 'Produktionswerk & Robotikzentrum Brescia',
                    'fr' => 'Usine de Production & Centre Robotique Brescia',
                ],
            ],
            [
                'code' => 'LOC-TUR-CON',
                'name' => 'Turin Construction & Heavy Fleet Depot',
                'location_type' => 'depot',
                'address' => 'Corso Francia 210',
                'city' => 'Torino',
                'country' => 'IT',
                'translations' => [
                    'it' => 'Deposito Cantiere e Flotta Pesante Torino',
                    'en' => 'Turin Construction & Heavy Fleet Depot',
                    'de' => 'Bau- und Schwerlastflottendepot Turin',
                    'fr' => 'Dépôt Chantier & Flotte Lourde Turin',
                ],
            ],
            [
                'code' => 'LOC-ROM-RET',
                'name' => 'Rome Flagship Retail & Consulting Hub',
                'location_type' => 'retail_store',
                'address' => 'Via del Corso 145',
                'city' => 'Roma',
                'country' => 'IT',
                'translations' => [
                    'it' => 'Flagship Retail & Hub Consulenza Roma',
                    'en' => 'Rome Flagship Retail & Consulting Hub',
                    'de' => 'Flagship-Store & Beratungszentrum Rom',
                    'fr' => 'Boutique Phare & Pôle Conseil Rome',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($locations as $loc) {
                $existingId = DB::table('eam_asset_locations')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $loc['code'])
                    ->value('id');

                DB::table('eam_asset_locations')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $loc['code'],
                    ],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'name' => $loc['name'],
                        'location_type' => $loc['location_type'],
                        'address' => $loc['address'],
                        'city' => $loc['city'],
                        'country' => $loc['country'],
                        'is_active' => true,
                        'metadata' => json_encode(['translations' => $loc['translations']]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
