<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\ShortTimeCase;
use Modules\Payroll\Models\ShortTimeClaim;

/**
 * @extends Factory<ShortTimeClaim>
 */
final class ShortTimeClaimFactory extends Factory
{
    protected $model = ShortTimeClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'short_time_case_id' => static fn (array $attributes): string => (string) ShortTimeCase::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'year' => 2026,
            'month' => 11,
            'status' => ShortTimeClaim::StatusDraft,
            'parameters_year' => 2026,
            'target_hours' => '1680.00',
            'lost_hours' => '420.00',
            'loss_percent' => '25.00',
            'meets_minimum_loss' => true,
            'total_compensation' => '11760.00',
            'total_employer_refund' => '12925.00',
            'claim_due_on' => '2027-02-28',
            'warnings' => [],
        ];
    }
}
