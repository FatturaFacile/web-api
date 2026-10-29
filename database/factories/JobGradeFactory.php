<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\JobGrade;

/**
 * @extends Factory<JobGrade>
 */
class JobGradeFactory extends Factory
{
    protected $model = JobGrade::class;

    public function definition(): array
    {
        $code = 'JG-'.strtoupper(Str::random(4));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => $code,
            'name' => 'Senior Professional '.$code,
            'level_rank' => 5,
            'min_salary' => 45000.00,
            'midpoint_salary' => 60000.00,
            'max_salary' => 75000.00,
            'currency' => 'EUR',
            'is_active' => true,
            'translations' => [
                'it' => 'Professionista Senior',
                'en' => 'Senior Professional',
                'de' => 'Senior Fachkraft',
                'fr' => 'Professionnel Senior',
            ],
        ];
    }
}
