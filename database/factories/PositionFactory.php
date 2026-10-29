<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\JobGrade;
use Modules\HumanResources\Models\Position;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        $code = 'POS-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'department_id' => function (array $attrs) {
                return Department::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'job_grade_id' => function (array $attrs) {
                return JobGrade::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'code' => $code,
            'title' => 'Senior Systems Engineer '.$code,
            'headcount_budget' => 5,
            'is_critical_role' => false,
            'is_active' => true,
            'translations' => [
                'it' => 'Ingegnere di Sistema Senior',
                'en' => 'Senior Systems Engineer',
                'de' => 'Senior Systemingenieur',
                'fr' => 'Ingénieur Système Senior',
            ],
            'competencies_required' => ['ERP Architecture', 'System Design'],
        ];
    }
}
