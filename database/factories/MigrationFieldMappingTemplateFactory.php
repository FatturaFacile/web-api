<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Models\MigrationFieldMappingTemplate;

/**
 * @extends Factory<MigrationFieldMappingTemplate>
 */
final class MigrationFieldMappingTemplateFactory extends Factory
{
    protected $model = MigrationFieldMappingTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'name' => 'Custom Mapping Template '.$this->faker->word(),
            'source_system' => MigrationSourceSystem::GenericCsvXlsx,
            'entity_type' => MigrationTargetEntity::Customers,
            'column_mappings_json' => [
                'company_name' => [
                    'source_column' => 'Ragione Sociale',
                    'required' => true,
                    'default_value' => null,
                    'confidence' => 1.0,
                ],
            ],
            'value_transformations_json' => [
                'date_format' => 'Y-m-d',
                'decimal_separator' => '.',
                'thousand_separator' => '',
                'currency_default' => 'EUR',
            ],
            'is_system_preset' => false,
        ];
    }
}
