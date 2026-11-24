<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Department;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        $code = 'DEP-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => $code,
            'name' => 'Engineering & Operations '.$code,
            'is_active' => true,
            'translations' => [
                'it' => 'Ingegneria e Operazioni',
                'en' => 'Engineering & Operations',
                'de' => 'Technik und Betrieb',
                'fr' => 'Ingénierie et Opérations',
            ],
            'metadata' => ['source' => 'factory'],
        ];
    }
}
