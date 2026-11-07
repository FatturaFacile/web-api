<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\DataSubjectRequest;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<DataSubjectRequest>
 */
final class DataSubjectRequestFactory extends Factory
{
    protected $model = DataSubjectRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $receivedAt = CarbonImmutable::now()->subDays(fake()->numberBetween(1, 20));

        return [
            'tenant_id' => Tenant::factory(),
            'kind' => 'access',
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'requester_name' => fake()->firstName().' '.fake()->lastName(),
            'requester_contact' => fake()->safeEmail(),
            'details' => 'Richiesta di copia dei dati personali trattati dall\'associazione.',
            'received_at' => $receivedAt,
            'due_on' => $receivedAt->addMonthNoOverflow()->toDateString(),
            'status' => 'received',
        ];
    }
}
