<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentLink;

/**
 * @extends Factory<DmsDocumentLink>
 */
final class DmsDocumentLinkFactory extends Factory
{
    protected $model = DmsDocumentLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => DmsDocument::factory(),
            'tenant_id' => fn (array $attributes): string => (string) DmsDocument::withoutGlobalScopes()->findOrFail($attributes['document_id'])->tenant_id,
            'linkable_type' => 'contact',
            'linkable_id' => (string) Str::uuid7(),
        ];
    }
}
