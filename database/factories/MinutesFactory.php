<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\MinutesStatus;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\Minutes;

/**
 * @extends Factory<Minutes>
 */
final class MinutesFactory extends Factory
{
    protected $model = Minutes::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'meeting_id' => static fn (array $attributes): string => Meeting::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => MinutesStatus::Draft,
            'title' => 'Verbale dell\'assemblea ordinaria dei soci',
            'body' => "Il giorno 15 marzo 2026 si è riunita l'assemblea ordinaria dei soci.\nPresiede il presidente, verbalizza il segretario.",
            'number' => null,
            'fiscal_year' => null,
            'sequential_number' => null,
        ];
    }
}
