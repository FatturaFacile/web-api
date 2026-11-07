<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\GeneralInterestActivity;

/**
 * @extends Factory<GeneralInterestActivity>
 */
final class GeneralInterestActivityFactory extends Factory
{
    protected $model = GeneralInterestActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => GeneralInterestActivity::GeneralInterest,
            'reference' => 'Art. 5 c.1 lett. '.fake()->randomElement(['a', 'd', 'i', 't']),
            'description' => fake()->randomElement([
                'Organizzazione e gestione di attività sportive dilettantistiche',
                'Organizzazione di attività culturali e ricreative di interesse sociale',
                'Educazione, istruzione e formazione professionale',
            ]),
            'statute_clause' => 'Art. '.fake()->numberBetween(2, 4).' dello statuto',
            'is_active' => true,
        ];
    }
}
