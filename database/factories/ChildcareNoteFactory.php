<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\Note;

/**
 * @extends Factory<Note>
 */
final class ChildcareNoteFactory extends Factory
{
    protected $model = Note::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'date' => fake()->dateTimeBetween('2026-09-01', '2026-12-18')->format('Y-m-d'),
            'category' => 'meal',
            'body' => 'Ha mangiato con appetito il primo e la frutta, ha lasciato metà del secondo.',
            'visible_to_family' => true,
            'author_id' => null,
        ];
    }
}
