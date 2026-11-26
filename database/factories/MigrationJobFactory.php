<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Models\MigrationJob;

/**
 * @extends Factory<MigrationJob>
 */
final class MigrationJobFactory extends Factory
{
    protected $model = MigrationJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'source_system' => MigrationSourceSystem::GenericCsvXlsx,
            'target_entities' => [MigrationTargetEntity::Customers->value],
            'status' => MigrationJobStatus::Uploaded,
            'dry_run_passed' => false,
            'total_rows' => 0,
            'imported_rows' => 0,
            'error_rows' => 0,
            'source_file_path' => 'migrations/sample.csv',
            'source_metadata_json' => [
                'file_name' => 'sample.csv',
                'extension' => 'csv',
                'headers' => ['company_name', 'country', 'vat_number'],
            ],
        ];
    }
}
