<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\PaymentBatch;

/**
 * @extends Factory<PaymentBatch>
 */
final class PaymentBatchFactory extends Factory
{
    protected $model = PaymentBatch::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'bank_account_id' => fn (array $attributes): string => BankAccountFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'format' => 'sepa_pain_001_it',
            'batch_reference' => 'BATCH-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'payment_method' => 'sepa_credit_transfer',
            'execution_date' => date('Y-m-d'),
            'currency' => 'EUR',
            'total_amount' => 5000.00,
            'item_count' => 1,
            'status' => 'draft',
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'DRAFT',
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function pendingApproval(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PENDING_APPROVAL',
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'APPROVED',
            'approved_by' => (string) Str::uuid7(),
            'approved_at' => now(),
        ]);
    }

    public function executed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'EXECUTED',
            'approved_by' => (string) Str::uuid7(),
            'approved_at' => now()->subHour(),
            'iso_message_id' => 'MSG-'.date('Ymd').'-'.strtoupper(Str::random(8)),
            'transmission_protocol' => 'sepa_pain001',
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'CANCELLED',
        ]);
    }
}
