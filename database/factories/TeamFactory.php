<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Team;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        $code = 'TM-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'department_id' => function (array $attrs) {
                return Department::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'code' => $code,
            'name' => 'Cross-Functional Team '.$code,
            'is_active' => true,
            'translations' => [
                'it' => 'Team Interfunzionale',
                'en' => 'Cross-Functional Team',
                'de' => 'Funktionsübergreifendes Team',
                'fr' => 'Équipe Interfonctionnelle',
            ],
        ];
    }
}
