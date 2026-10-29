<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TicketPrioritySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $priorities = [
            [
                'code' => 'PRI_CRITICAL',
                'name' => 'Critical / Outage',
                'priority' => 'critical',
                'response_time_minutes' => 60,
                'resolution_time_minutes' => 240,
                'escalation_time_minutes' => 30,
                'translations' => [
                    'it' => 'Critica / Blocco Servizio',
                    'en' => 'Critical / Outage',
                    'de' => 'Kritisch / Totalausfall',
                    'fr' => 'Critique / Panne Majeure',
                ],
            ],
            [
                'code' => 'PRI_HIGH',
                'name' => 'High / Major Impairment',
                'priority' => 'high',
                'response_time_minutes' => 120,
                'resolution_time_minutes' => 480,
                'escalation_time_minutes' => 60,
                'translations' => [
                    'it' => 'Alta / Forte Degrado',
                    'en' => 'High / Major Impairment',
                    'de' => 'Hoch / Erhebliche Beeinträchtigung',
                    'fr' => 'Haute / Dégradation Majeure',
                ],
            ],
            [
                'code' => 'PRI_MEDIUM',
                'name' => 'Medium / Standard Support',
                'priority' => 'medium',
                'response_time_minutes' => 240,
                'resolution_time_minutes' => 1440,
                'escalation_time_minutes' => 120,
                'translations' => [
                    'it' => 'Media / Supporto Standard',
                    'en' => 'Medium / Standard Support',
                    'de' => 'Mittel / Standardunterstützung',
                    'fr' => 'Moyenne / Support Standard',
                ],
            ],
            [
                'code' => 'PRI_LOW',
                'name' => 'Low / Minor Query',
                'priority' => 'low',
                'response_time_minutes' => 480,
                'resolution_time_minutes' => 2880,
                'escalation_time_minutes' => 240,
                'translations' => [
                    'it' => 'Bassa / Richiesta Minore',
                    'en' => 'Low / Minor Query',
                    'de' => 'Niedrig / Geringe Priorität',
                    'fr' => 'Basse / Demande Mineure',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($priorities as $p) {
                $existingId = DB::table('esm_sla_policies')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $p['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('esm_sla_policies')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $p['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $p['name'],
                        'priority' => $p['priority'],
                        'response_time_minutes' => $p['response_time_minutes'],
                        'resolution_time_minutes' => $p['resolution_time_minutes'],
                        'escalation_time_minutes' => $p['escalation_time_minutes'],
                        'business_hours_only' => true,
                        'is_active' => true,
                        'translations' => json_encode($p['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
