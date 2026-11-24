<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\HolidayCalendar;

/**
 * @extends Factory<HolidayCalendar>
 */
class HolidayCalendarFactory extends Factory
{
    protected $model = HolidayCalendar::class;

    public function definition(): array
    {
        $code = 'CAL-'.strtoupper(Str::random(4));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => $code,
            'name' => 'Italian National Calendar '.$code,
            'country_code' => 'IT',
            'year' => (int) date('Y'),
            'daily_standard_hours' => 8.00,
            'working_days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'],
            'holidays' => [
                ['date' => date('Y').'-01-01', 'name' => 'Capodanno'],
                ['date' => date('Y').'-04-25', 'name' => 'Festa della Liberazione'],
                ['date' => date('Y').'-05-01', 'name' => 'Festa dei Lavoratori'],
                ['date' => date('Y').'-06-02', 'name' => 'Festa della Repubblica'],
                ['date' => date('Y').'-08-15', 'name' => 'Ferragosto'],
                ['date' => date('Y').'-12-25', 'name' => 'Natale'],
            ],
            'is_active' => true,
            'translations' => [
                'it' => 'Calendario Festività Italia',
                'en' => 'Italian National Holiday Calendar',
                'de' => 'Italienischer Feiertagskalender',
                'fr' => 'Calendrier des Jours Fériés Italie',
            ],
        ];
    }
}
