<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\Ballot;
use Modules\Associations\Models\BallotVoter;

/**
 * @extends Factory<BallotVoter>
 */
final class BallotVoterFactory extends Factory
{
    protected $model = BallotVoter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'ballot_id' => static fn (array $attributes): string => Ballot::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'participant_id' => (string) Str::uuid7(),
            'cast_by_participant_id' => null,
            'channel' => 'in_person',
            'voted_at' => now()->subHour(),
        ];
    }
}
