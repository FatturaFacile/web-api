<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\LeaveType;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        $code = 'LV-'.strtoupper(Str::random(4));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => $code,
            'name' => 'Annual Vacation '.$code,
            'category' => 'vacation',
            'is_paid' => true,
            'requires_approval' => true,
            'requires_attachment' => false,
            'annual_entitlement_hours' => 160.00,
            'accrual_frequency' => 'monthly',
            'max_carryover_hours' => 80.00,
            'is_active' => true,
            'translations' => [
                'it' => 'Ferie Annuali',
                'en' => 'Annual Vacation',
                'de' => 'Jahresurlaub',
                'fr' => 'Congés Annuels',
            ],
        ];
    }
}
