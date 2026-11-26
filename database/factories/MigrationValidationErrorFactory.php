<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Models\MigrationValidationError;

/**
 * @extends Factory<MigrationValidationError>
 */
final class MigrationValidationErrorFactory extends Factory
{
    protected $model = MigrationValidationError::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'migration_job_id' => MigrationJob::factory(),
            'row_index' => 1,
            'source_record_identifier' => 'ROW-1',
            'entity_type' => MigrationTargetEntity::Customers->value,
            'field_name' => 'vat_number',
            'raw_value' => '12345678901',
            'error_code' => 'INVALID_ITALIAN_VAT',
            'message_key' => 'dataMigration.error.INVALID_ITALIAN_VAT',
            'suggested_fix' => '01234560153',
            'is_resolved' => false,
        ];
    }
}
