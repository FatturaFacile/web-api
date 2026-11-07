<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\LedgerPostingStatus;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsLedgerPosting;

/**
 * @extends Factory<CustomsLedgerPosting>
 */
final class CustomsLedgerPostingFactory extends Factory
{
    protected $model = CustomsLedgerPosting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'assessment_decision_id' => static fn (array $attributes): string => (string) CustomsAssessmentDecision::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => LedgerPostingStatus::Pending,
            'posting_date' => static fn (array $attributes): string => CustomsAssessmentDecision::query()->withoutGlobalScopes()->findOrFail($attributes['assessment_decision_id'])->document_date->toDateString(),
            'duty_amount' => static fn (array $attributes): string => number_format((float) CustomsAssessmentDecision::query()->withoutGlobalScopes()->findOrFail($attributes['assessment_decision_id'])->duty_amount, 2, '.', ''),
            'vat_amount' => static fn (array $attributes): string => number_format((float) CustomsAssessmentDecision::query()->withoutGlobalScopes()->findOrFail($attributes['assessment_decision_id'])->vat_amount, 2, '.', ''),
        ];
    }
}
