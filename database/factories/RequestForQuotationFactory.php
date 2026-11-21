<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\ValueObjects\EvaluationWeights;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RequestForQuotation>
 */
final class RequestForQuotationFactory extends Factory
{
    protected $model = RequestForQuotation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId()
            ?? Tenant::query()->value('id')
            ?? (string) Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => fake()->company(),
                'legal_name' => fake()->company().' Srl',
                'country' => 'IT',
                'default_currency' => 'EUR',
            ])->id;

        $deadline = now('UTC')->addDays(7);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'rfq_number' => 'RFQ-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'title' => fake()->words(4, true),
            'description' => fake()->sentence(),
            'status' => RfqStatus::Draft->value,
            'currency' => 'EUR',
            'incoterms' => 'DDP',
            'response_deadline' => $deadline->toDateString(),
            'submission_deadline' => $deadline,
            'expected_delivery_date' => now('UTC')->addDays(21)->toDateString(),
            'evaluation_weights' => EvaluationWeights::default()->toArray(),
            'sealed_bidding' => false,
        ];
    }

    public function biddingOpen(): static
    {
        return $this->state(fn (): array => [
            'status' => RfqStatus::BiddingOpen->value,
            'issue_date' => now()->toDateString(),
        ]);
    }

    public function sealed(): static
    {
        return $this->state(fn (): array => [
            'sealed_bidding' => true,
        ]);
    }
}
