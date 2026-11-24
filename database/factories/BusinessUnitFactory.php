<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\BusinessUnit;

/**
 * @extends Factory<BusinessUnit>
 */
class BusinessUnitFactory extends Factory
{
    protected $model = BusinessUnit::class;

    public function definition(): array
    {
        $code = 'BU-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => $code,
            'name' => 'Industrial Operations Division '.$code,
            'is_active' => true,
            'translations' => [
                'it' => 'Divisione Operazioni Industriali',
                'en' => 'Industrial Operations Division',
                'de' => 'Geschäftsbereich Industriebetrieb',
                'fr' => 'Division des Opérations Industrielles',
            ],
            'metadata' => ['source' => 'factory'],
        ];
    }
}
