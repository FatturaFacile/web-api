<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Models\ReimbursementAttachment;

/**
 * @extends Factory<ReimbursementAttachment>
 */
final class ReimbursementAttachmentFactory extends Factory
{
    protected $model = ReimbursementAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reimbursement_id' => static fn (array $attributes): string => Reimbursement::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'disk' => 'local',
            'path' => static fn (): string => 'associations/reimbursements/'.Str::uuid7().'.jpg',
            'original_name' => 'scontrino.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(30_000, 3_000_000),
        ];
    }
}
