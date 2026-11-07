<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\CustomsChannel;
use Modules\Customs\Models\CustomsSettings;

/**
 * @extends Factory<CustomsSettings>
 */
final class CustomsSettingsFactory extends Factory
{
    protected $model = CustomsSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'channel' => CustomsChannel::Local->value,
            'auto_retrieve' => false,
            'trader_identification_number' => fake()->numerify('CHE#########'),
            'declarant_number' => fake()->numberBetween(10000, 99999),
            'declarant_name' => 'Spedizioni Ticino SA',
            'declarant_street' => 'Via Industria 12',
            'declarant_postal_code' => '6850',
            'declarant_city' => 'Mendrisio',
            'declarant_country' => 'CH',
            'customs_account' => fake()->numerify('########'),
            'vat_account' => fake()->numerify('########'),
            'vat_number' => fake()->numerify('CHE#########'),
        ];
    }
}
