<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\DmsDocument;

/**
 * @extends Factory<DmsDocument>
 */
final class DmsDocumentFactory extends Factory
{
    protected $model = DmsDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => $this->faker->sentence(3),
            'status' => 'active',
            'access_roles' => [],
            'metadata' => [],
        ];
    }
}
