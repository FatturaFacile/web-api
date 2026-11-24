<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HolidayCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $year = (int) date('Y');

        $calendars = [
            [
                'code' => 'CAL_IT_MILAN',
                'name' => 'Italy National & Milan Patron Saint Calendar',
                'country_code' => 'IT',
                'year' => $year,
                'holidays' => [
                    ['date' => "{$year}-01-01", 'name' => 'Capodanno'],
                    ['date' => "{$year}-01-06", 'name' => 'Epifania'],
                    ['date' => "{$year}-04-25", 'name' => 'Festa della Liberazione'],
                    ['date' => "{$year}-05-01", 'name' => 'Festa dei Lavoratori'],
                    ['date' => "{$year}-06-02", 'name' => 'Festa della Repubblica'],
                    ['date' => "{$year}-08-15", 'name' => 'Ferragosto'],
                    ['date' => "{$year}-11-01", 'name' => 'Ognissanti'],
                    ['date' => "{$year}-12-07", 'name' => 'Sant\'Ambrogio (Patrono Milano)'],
                    ['date' => "{$year}-12-08", 'name' => 'Immacolata Concezione'],
                    ['date' => "{$year}-12-25", 'name' => 'Natale'],
                    ['date' => "{$year}-12-26", 'name' => 'Santo Stefano'],
                ],
                'translations' => [
                    'it' => 'Calendario Festività Italia e Santo Patrono Milano',
                    'en' => 'Italy National & Milan Patron Saint Calendar',
                    'de' => 'Italienischer National- und Mailänder Feiertagskalender',
                    'fr' => 'Calendrier National Italie et Saint Patron Milan',
                ],
            ],
            [
                'code' => 'CAL_CH_TICINO',
                'name' => 'Switzerland Canton Ticino Cross-Border Calendar',
                'country_code' => 'CH',
                'year' => $year,
                'holidays' => [
                    ['date' => "{$year}-01-01", 'name' => 'Capodanno'],
                    ['date' => "{$year}-01-06", 'name' => 'Epifania'],
                    ['date' => "{$year}-03-19", 'name' => 'San Giuseppe'],
                    ['date' => "{$year}-08-01", 'name' => 'Festa Nazionale Svizzera'],
                    ['date' => "{$year}-08-15", 'name' => 'Assunzione'],
                    ['date' => "{$year}-12-25", 'name' => 'Natale'],
                ],
                'translations' => [
                    'it' => 'Calendario Festività Canton Ticino (Svizzera)',
                    'en' => 'Switzerland Canton Ticino Cross-Border Calendar',
                    'de' => 'Feiertagskalender Kanton Tessin (Schweiz)',
                    'fr' => 'Calendrier des Jours Fériés Canton du Tessin (Suisse)',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($calendars as $cal) {
                $existingId = DB::table('hr_holiday_calendars')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $cal['code'])
                    ->where('year', $cal['year'])
                    ->value('id');

                DB::table('hr_holiday_calendars')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $cal['code'],
                        'year' => $cal['year'],
                    ],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $cal['name'],
                        'country_code' => $cal['country_code'],
                        'holidays' => json_encode($cal['holidays']),
                        'is_active' => true,
                        'translations' => json_encode($cal['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
