<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\DocumentRequirement;

/**
 * @extends Factory<DocumentRequirement>
 */
final class ChildcareDocumentRequirementFactory extends Factory
{
    protected $model = DocumentRequirement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Certificato vaccinale', 'Consenso uscite didattiche', 'Autorizzazione foto e video', 'Contratto di accoglienza']),
            'requires_signature' => true,
            'is_mandatory' => true,
        ];
    }
}
