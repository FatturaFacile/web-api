<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $businessUnits = [
            [
                'code' => 'BU_MFG',
                'name' => 'Manufacturing & Industrial Division',
                'translations' => [
                    'it' => 'Divisione Manifatturiera e Industriale',
                    'en' => 'Manufacturing & Industrial Division',
                    'de' => 'Geschäftsbereich Fertigung und Industrie',
                    'fr' => 'Division Fabrication et Industrie',
                ],
            ],
            [
                'code' => 'BU_CONST',
                'name' => 'Construction & Infrastructure Division',
                'translations' => [
                    'it' => 'Divisione Costruzioni e Infrastrutture',
                    'en' => 'Construction & Infrastructure Division',
                    'de' => 'Geschäftsbereich Bau und Infrastruktur',
                    'fr' => 'Division Construction et Infrastructures',
                ],
            ],
            [
                'code' => 'BU_PRO',
                'name' => 'Professional Services & Consulting Division',
                'translations' => [
                    'it' => 'Divisione Servizi Professionali e Consulenza',
                    'en' => 'Professional Services & Consulting Division',
                    'de' => 'Geschäftsbereich Professionelle Dienstleistungen',
                    'fr' => 'Division Services Professionnels et Conseil',
                ],
            ],
            [
                'code' => 'BU_RET',
                'name' => 'Retail & Omnichannel Commerce Division',
                'translations' => [
                    'it' => 'Divisione Retail e Commercio Omnicanale',
                    'en' => 'Retail & Omnichannel Commerce Division',
                    'de' => 'Geschäftsbereich Einzelhandel und Omnichannel',
                    'fr' => 'Division Commerce de Détail et Omnicanal',
                ],
            ],
        ];

        $costCenters = [
            [
                'code' => 'CC_MFG_OPS',
                'name' => 'CNC & Assembly Production Cost Center',
                'gl_expense_account_code' => '6110',
                'annual_budget' => 1850000.00,
                'translations' => [
                    'it' => 'Centro di Costo Produzione CNC e Assemblaggio',
                    'en' => 'CNC & Assembly Production Cost Center',
                    'de' => 'Kostenstelle CNC-Fertigung und Montage',
                    'fr' => 'Centre de Coûts Production CNC et Assemblage',
                ],
            ],
            [
                'code' => 'CC_CONST_SITE',
                'name' => 'Civil Engineering & Site Operations Cost Center',
                'gl_expense_account_code' => '6120',
                'annual_budget' => 1400000.00,
                'translations' => [
                    'it' => 'Centro di Costo Ingegneria Civile e Cantieri',
                    'en' => 'Civil Engineering & Site Operations Cost Center',
                    'de' => 'Kostenstelle Tiefbau und Baustellenbetrieb',
                    'fr' => 'Centre de Coûts Génie Civil et Chantiers',
                ],
            ],
            [
                'code' => 'CC_PRO_ADV',
                'name' => 'Enterprise Advisory & Engineering Cost Center',
                'gl_expense_account_code' => '6130',
                'annual_budget' => 1200000.00,
                'translations' => [
                    'it' => 'Centro di Costo Consulenza Direzionale e Ingegneria',
                    'en' => 'Enterprise Advisory & Engineering Cost Center',
                    'de' => 'Kostenstelle Unternehmensberatung und Engineering',
                    'fr' => 'Centre de Coûts Conseil d\'Entreprise et Ingénierie',
                ],
            ],
            [
                'code' => 'CC_RET_STORE',
                'name' => 'Flagship Stores & POS Operations Cost Center',
                'gl_expense_account_code' => '6140',
                'annual_budget' => 950000.00,
                'translations' => [
                    'it' => 'Centro di Costo Negozi Flagship e Operazioni POS',
                    'en' => 'Flagship Stores & POS Operations Cost Center',
                    'de' => 'Kostenstelle Flagship-Stores und Kassenbetrieb',
                    'fr' => 'Centre de Coûts Magasins Phares et Opérations POS',
                ],
            ],
        ];

        $departments = [
            [
                'code' => 'DEP_MFG',
                'bu_code' => 'BU_MFG',
                'cc_code' => 'CC_MFG_OPS',
                'name' => 'Precision Manufacturing & Quality Assurance',
                'translations' => [
                    'it' => 'Produzione di Precisione e Controllo Qualità',
                    'en' => 'Precision Manufacturing & Quality Assurance',
                    'de' => 'Präzisionsfertigung und Qualitätssicherung',
                    'fr' => 'Fabrication de Précision et Assurance Qualité',
                ],
            ],
            [
                'code' => 'DEP_CONST',
                'bu_code' => 'BU_CONST',
                'cc_code' => 'CC_CONST_SITE',
                'name' => 'Site Engineering & Construction Management',
                'translations' => [
                    'it' => 'Ingegneria di Cantiere e Direzione Lavori',
                    'en' => 'Site Engineering & Construction Management',
                    'de' => 'Baustellentechnik und Bauleitung',
                    'fr' => 'Ingénierie de Chantier et Direction des Travaux',
                ],
            ],
            [
                'code' => 'DEP_PRO',
                'bu_code' => 'BU_PRO',
                'cc_code' => 'CC_PRO_ADV',
                'name' => 'Digital Transformation & Advisory Practice',
                'translations' => [
                    'it' => 'Trasformazione Digitale e Consulenza Strategica',
                    'en' => 'Digital Transformation & Advisory Practice',
                    'de' => 'Digitale Transformation und Strategieberatung',
                    'fr' => 'Transformation Numérique et Conseil Stratégique',
                ],
            ],
            [
                'code' => 'DEP_RET',
                'bu_code' => 'BU_RET',
                'cc_code' => 'CC_RET_STORE',
                'name' => 'Retail Store Network & Customer Experience',
                'translations' => [
                    'it' => 'Rete Negozi Retail ed Esperienza Cliente',
                    'en' => 'Retail Store Network & Customer Experience',
                    'de' => 'Filialnetz und Kundenerlebnis',
                    'fr' => 'Réseau de Magasins et Expérience Client',
                ],
            ],
            [
                'code' => 'DEP_HR_CORP',
                'bu_code' => 'BU_PRO',
                'cc_code' => 'CC_PRO_ADV',
                'name' => 'Human Resources & People Operations',
                'translations' => [
                    'it' => 'Risorse Umane e Organizzazione del Lavoro',
                    'en' => 'Human Resources & People Operations',
                    'de' => 'Personalwesen und People Operations',
                    'fr' => 'Ressources Humaines et Opérations RH',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            $buMap = [];
            foreach ($businessUnits as $bu) {
                $existingId = DB::table('hr_business_units')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $bu['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('hr_business_units')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $bu['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $bu['name'],
                        'is_active' => true,
                        'translations' => json_encode($bu['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $buMap[$bu['code']] = $id;
            }

            $ccMap = [];
            foreach ($costCenters as $cc) {
                $existingId = DB::table('hr_cost_centers')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $cc['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('hr_cost_centers')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $cc['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $cc['name'],
                        'gl_expense_account_code' => $cc['gl_expense_account_code'],
                        'gl_accrual_account_code' => '2250',
                        'annual_budget' => $cc['annual_budget'],
                        'is_active' => true,
                        'translations' => json_encode($cc['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $ccMap[$cc['code']] = $id;
            }

            foreach ($departments as $dep) {
                $existingId = DB::table('hr_departments')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $dep['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('hr_departments')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $dep['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'business_unit_id' => $buMap[$dep['bu_code']] ?? null,
                        'cost_center_id' => $ccMap[$dep['cc_code']] ?? null,
                        'name' => $dep['name'],
                        'is_active' => true,
                        'translations' => json_encode($dep['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
