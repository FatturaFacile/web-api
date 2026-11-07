<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\ChildDocument;
use Modules\Childcare\Models\DocumentRequirement;

/**
 * @extends Factory<ChildDocument>
 */
final class ChildcareChildDocumentFactory extends Factory
{
    protected $model = ChildDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'requirement_id' => static fn (array $attributes): string => (string) ChildcareDocumentRequirementFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => static fn (array $attributes): string => (string) DocumentRequirement::query()->withoutGlobalScopes()->findOrFail($attributes['requirement_id'])->name,
            'status' => 'requested',
        ];
    }
}
