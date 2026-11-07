<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsDocumentType;

/**
 * @extends Factory<CustomsDocumentType>
 */
final class CustomsDocumentTypeFactory extends Factory
{
    protected $model = CustomsDocumentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => fake()->unique()->numerify('N###'),
            'name_de' => 'Handelsrechnung',
            'name_fr' => 'Facture commerciale',
            'name_it' => 'Fattura commerciale',
            'name_en' => 'Commercial invoice',
            'valid_from' => '2026-01-01',
            'is_active' => true,
            'source' => 'tenant',
        ];
    }

    /**
     * Official BAZG row shared by every company and read-only for them.
     */
    public function generic(): self
    {
        return $this->state(['tenant_id' => null, 'source' => 'bazg_edec']);
    }
}
