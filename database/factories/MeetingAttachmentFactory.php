<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingAttachment;

/**
 * @extends Factory<MeetingAttachment>
 */
final class MeetingAttachmentFactory extends Factory
{
    protected $model = MeetingAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'meeting_id' => static fn (array $attributes): string => Meeting::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'disk' => 'local',
            'path' => static fn (): string => 'associations/meetings/'.Str::uuid7().'.pdf',
            'original_name' => 'convocazione.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(20_000, 900_000),
        ];
    }
}
