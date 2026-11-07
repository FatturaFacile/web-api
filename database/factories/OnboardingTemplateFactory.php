<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\OnboardingTemplate;

/**
 * @extends Factory<OnboardingTemplate>
 */
final class OnboardingTemplateFactory extends Factory
{
    protected $model = OnboardingTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Inserimento '.fake()->randomElement(['amministrazione', 'produzione', 'vendite', 'IT']).' '.fake()->unique()->numerify('###'),
            'tasks' => [
                ['title' => 'Firma del contratto e consegna documenti', 'due_after_days' => 0],
                ['title' => 'Consegna badge e postazione di lavoro', 'due_after_days' => 0],
                ['title' => 'Formazione sicurezza sul lavoro', 'due_after_days' => 7],
                ['title' => 'Colloquio di fine periodo di prova', 'due_after_days' => 90],
            ],
        ];
    }
}
