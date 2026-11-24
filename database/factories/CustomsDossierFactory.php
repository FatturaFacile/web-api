<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\DossierStatus;
use Modules\Customs\Models\CustomsDossier;

/**
 * @extends Factory<CustomsDossier>
 */
class CustomsDossierFactory extends Factory
{
    protected $model = CustomsDossier::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'dossier_number' => strtoupper(fake()->unique()->bothify('DOS-########')),
            'status' => DossierStatus::Open,
            'service_type' => 'road',
            'direction' => 'import',
            'currency' => 'CHF',
        ];
    }
}
