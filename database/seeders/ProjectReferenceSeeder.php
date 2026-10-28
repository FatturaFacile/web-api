<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ProjectReferenceSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Resource Trades & Roles Lookup
        $definitions = [
            [
                'code' => 'project_resource_trade',
                'name' => 'Project Resource Disciplines & Trades',
                'description' => 'Professional competencies and cost rate billing classifications',
                'items' => [
                    ['code' => 'pm', 'sort' => 10, 'translations' => ['en' => 'Principal Project Manager', 'it' => 'Responsabile di Commessa / PM', 'de' => 'Projektleiter', 'fr' => 'Directeur de Projet']],
                    ['code' => 'architect', 'sort' => 20, 'translations' => ['en' => 'Chief Solution Architect', 'it' => 'Architetto Capo / Progettista', 'de' => 'Chefarchitekt', 'fr' => 'Architecte Principal']],
                    ['code' => 'engineer', 'sort' => 30, 'translations' => ['en' => 'Senior Site / Systems Engineer', 'it' => 'Ingegnere di Cantiere / Impiantista', 'de' => 'Bauleiter / Projektingenieur', 'fr' => 'Ingénieur Travaux / Systèmes']],
                    ['code' => 'foreman', 'sort' => 40, 'translations' => ['en' => 'Site Foreman & Operations Lead', 'it' => 'Capocantiere / Caposquadra', 'de' => 'Polier / Bauvorarbeiter', 'fr' => 'Chef de Chantier']],
                    ['code' => 'technician', 'sort' => 50, 'translations' => ['en' => 'Certified Specialist Technician', 'it' => 'Tecnico Specializzato Certificato', 'de' => 'Servicetechniker', 'fr' => 'Technicien Qualifié']],
                    ['code' => 'analyst', 'sort' => 60, 'translations' => ['en' => 'Business & Quality Analyst', 'it' => 'Analista Funzionale & Qualità', 'de' => 'Business Analyst', 'fr' => 'Consultant Métier']],
                ],
            ],
        ];

        foreach ($definitions as $def) {
            $cat = LookupCategory::updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => true,
                ]
            );

            foreach ($def['items'] as $item) {
                $lookupItem = LookupItem::updateOrCreate(
                    [
                        'category_id' => $cat->id,
                        'code' => $item['code'],
                    ],
                    [
                        'sort_order' => $item['sort'],
                        'is_active' => true,
                        'is_default' => false,
                    ]
                );

                foreach (['en', 'it', 'de', 'fr'] as $lang) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'lookup_item_id' => $lookupItem->id,
                            'locale' => $lang,
                        ],
                        [
                            'label' => $item['translations'][$lang],
                            'description' => $item['translations'][$lang],
                        ]
                    );
                }
            }
        }

        // 2. Default Project Portfolios
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';
        $companyId = Schema::hasTable('companies') ? DB::table('companies')->value('id') : null;
        $companyId = $companyId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c';

        $managerUserId = DB::table('users')->value('id') ?? '614c3ab6-f35f-4f43-8f25-60d1e5fe038d';

        $portfolios = [
            [
                'code' => 'PORT-INFRA',
                'name' => 'Strategic Infrastructure & Energy Transition',
                'description' => 'High-capital construction, solar PV, and plant revamping initiatives',
            ],
            [
                'code' => 'PORT-DIGITAL',
                'name' => 'Enterprise Digitalization & ERP Modernization',
                'description' => 'Multi-tier digital transformation and cloud operations portfolio',
            ],
            [
                'code' => 'PORT-SERVICES',
                'name' => 'Client Professional Services & Advisory',
                'description' => 'Time-and-materials and milestone-based external consulting engagements',
            ],
        ];

        foreach ($portfolios as $portfolio) {
            DB::table('project_portfolios')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'code' => $portfolio['code'],
                ],
                [
                    'id' => DB::table('project_portfolios')->where('tenant_id', $tenantId)->where('code', $portfolio['code'])->value('id') ?? (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'name' => $portfolio['name'],
                    'description' => $portfolio['description'],
                    'portfolio_manager_user_id' => $managerUserId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
