<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\DataMigration\Models\MigrationFieldMappingTemplate;
use Modules\DataMigration\Services\ConnectorPresetRegistry;

/**
 * Seeds global system preset mapping templates for all 11 source ERP connectors.
 */
final class DataMigrationPresetSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(ConnectorPresetRegistry::class);

        foreach ($registry->systemPresets() as $preset) {
            $existing = MigrationFieldMappingTemplate::query()
                ->whereNull('tenant_id')
                ->where('source_system', $preset['source_system']->value)
                ->where('entity_type', $preset['entity_type']->value)
                ->where('is_system_preset', true)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'name' => $preset['name'],
                    'column_mappings_json' => $preset['column_mappings_json'],
                    'value_transformations_json' => $preset['value_transformations_json'],
                ]);
            } else {
                MigrationFieldMappingTemplate::createGlobal([
                    'id' => (string) Str::uuid7(),
                    'name' => $preset['name'],
                    'source_system' => $preset['source_system'],
                    'entity_type' => $preset['entity_type'],
                    'column_mappings_json' => $preset['column_mappings_json'],
                    'value_transformations_json' => $preset['value_transformations_json'],
                    'is_system_preset' => true,
                ]);
            }
        }
    }
}
