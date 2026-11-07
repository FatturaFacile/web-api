<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\Ballot;
use Modules\Associations\Models\BallotVote;

/**
 * @extends Factory<BallotVote>
 */
final class BallotVoteFactory extends Factory
{
    protected $model = BallotVote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'ballot_id' => static fn (array $attributes): string => Ballot::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'choice' => fake()->randomElement(['favorevole', 'contrario', 'astenuto']),
            'participant_id' => (string) Str::uuid7(),
        ];
    }
}
