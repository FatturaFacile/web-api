<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\Documents\Models\DmsExtraction;

/**
 * @extends Factory<DmsExtraction>
 */
final class DmsExtractionFactory extends Factory
{
    protected $model = DmsExtraction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_version_id' => DmsDocumentVersion::factory(),
            'tenant_id' => fn (array $attributes): string => (string) DmsDocumentVersion::withoutGlobalScopes()->findOrFail($attributes['document_version_id'])->tenant_id,
            'document_id' => fn (array $attributes): string => (string) DmsDocumentVersion::withoutGlobalScopes()->findOrFail($attributes['document_version_id'])->document_id,
            'status' => 'proposed',
            'driver' => 'local',
            'proposed_fields' => [],
        ];
    }
}
