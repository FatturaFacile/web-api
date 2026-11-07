<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsDossierDocument;

/**
 * @extends Factory<CustomsDossierDocument>
 */
final class CustomsDossierDocumentFactory extends Factory
{
    protected $model = CustomsDossierDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fileId = (string) Str::uuid7();

        return [
            'tenant_id' => Tenant::factory(),
            'dossier_id' => static fn (array $attributes): string => (string) CustomsDossier::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'document_kind' => 'invoice',
            'file_name' => 'fattura-commerciale-'.fake()->numerify('2026-####').'.pdf',
            'storage_path' => static fn (array $attributes): string => "customs/{$attributes['tenant_id']}/dossiers/{$attributes['dossier_id']}/{$fileId}.pdf",
            'sha256' => hash('sha256', $fileId),
        ];
    }
}
