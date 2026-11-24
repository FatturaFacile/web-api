<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\BallotStatus;
use Modules\Associations\Enums\MajorityRule;
use Modules\Associations\Models\Ballot;
use Modules\Associations\Models\Meeting;

/**
 * @extends Factory<Ballot>
 */
final class BallotFactory extends Factory
{
    protected $model = Ballot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'meeting_id' => static fn (array $attributes): string => Meeting::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'question' => 'Approvazione del rendiconto per l\'esercizio 2026',
            'options' => ['favorevole', 'contrario', 'astenuto'],
            'is_secret' => false,
            'majority_rule' => MajorityRule::Simple,
            'status' => BallotStatus::Draft,
        ];
    }

    public function secret(): static
    {
        return $this->state(['is_secret' => true]);
    }
}
