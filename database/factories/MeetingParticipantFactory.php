<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\AttendanceStatus;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<MeetingParticipant>
 */
final class MeetingParticipantFactory extends Factory
{
    protected $model = MeetingParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'meeting_id' => static fn (array $attributes): string => Meeting::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'is_eligible_voter' => true,
            'attendance' => AttendanceStatus::Invited,
            'attendance_recorded_at' => null,
            'proxy_holder_id' => null,
        ];
    }
}
