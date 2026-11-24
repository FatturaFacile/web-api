<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\DeliveryChannel;
use Modules\Associations\Models\AssociationDelivery;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<AssociationDelivery>
 */
final class AssociationDeliveryFactory extends Factory
{
    protected $model = AssociationDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'subject_type' => AssociationDelivery::MeetingNotice,
            'subject_id' => static fn (array $attributes): string => Meeting::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'channel' => DeliveryChannel::Email,
            'recipient' => fake()->safeEmail(),
            'status' => AssociationDelivery::Sent,
            'driver' => 'mail',
            'attempted_at' => now()->subDay(),
        ];
    }
}
