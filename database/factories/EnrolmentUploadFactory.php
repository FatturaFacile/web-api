<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentFormDocument;
use Modules\Associations\Models\EnrolmentUpload;

/**
 * @extends Factory<EnrolmentUpload>
 */
final class EnrolmentUploadFactory extends Factory
{
    protected $model = EnrolmentUpload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'application_id' => static fn (array $attributes): string => EnrolmentApplication::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'form_document_id' => static fn (array $attributes): string => EnrolmentFormDocument::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'form_id' => EnrolmentApplication::query()->withoutGlobalScopes()->findOrFail($attributes['application_id'])->form_id,
            ])->id,
            'disk' => 'local',
            'path' => 'associations/enrolments/'.Str::uuid7().'.pdf',
            'original_name' => 'certificato-medico.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(40_000, 2_000_000),
        ];
    }
}
