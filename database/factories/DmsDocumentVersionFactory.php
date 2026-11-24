<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;

/**
 * @extends Factory<DmsDocumentVersion>
 */
final class DmsDocumentVersionFactory extends Factory
{
    protected $model = DmsDocumentVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => DmsDocument::factory(),
            'tenant_id' => fn (array $attributes): string => (string) DmsDocument::withoutGlobalScopes()->findOrFail($attributes['document_id'])->tenant_id,
            'version_number' => 1,
            'disk' => 'local',
            'path' => 'tenants/test/dms/'.$this->faker->uuid().'.txt',
            'original_name' => 'file.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'sha256' => hash('sha256', $this->faker->uuid()),
        ];
    }
}
