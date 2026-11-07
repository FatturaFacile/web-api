<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Enums\ApprovalMode;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\MembershipType;

/**
 * @extends Factory<EnrolmentForm>
 */
final class EnrolmentFormFactory extends Factory
{
    protected $model = EnrolmentForm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'public_token' => Str::random(48),
            'title' => 'Iscrizione stagione 2026/2027',
            'description' => 'Modulo online di richiesta di adesione all\'associazione.',
            'year_id' => static fn (array $attributes): string => AssociativeYear::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'membership_type_id' => static fn (array $attributes): string => MembershipType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'fields' => [],
            'approval_mode' => ApprovalMode::Automatic,
            'is_active' => true,
            'opens_at' => '2026-08-01 00:00:00',
            'closes_at' => '2027-06-30 23:59:59',
        ];
    }

    public function boardApproval(): static
    {
        return $this->state(['approval_mode' => ApprovalMode::Board]);
    }
}
