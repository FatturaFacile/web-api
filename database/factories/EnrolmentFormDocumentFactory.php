<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\DocumentMode;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\EnrolmentFormDocument;

/**
 * @extends Factory<EnrolmentFormDocument>
 */
final class EnrolmentFormDocumentFactory extends Factory
{
    protected $model = EnrolmentFormDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'form_id' => static fn (array $attributes): string => EnrolmentForm::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => 'Certificato medico sportivo',
            'mode' => DocumentMode::Required,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    public function downloadOnly(): static
    {
        return $this->state([
            'title' => 'Regolamento interno',
            'mode' => DocumentMode::DownloadOnly,
            'disk' => 'local',
            'path' => 'associations/forms/regolamento-interno.pdf',
            'original_name' => 'regolamento-interno.pdf',
        ]);
    }
}
