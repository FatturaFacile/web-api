<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\ReceivableCollection;

/**
 * @extends Factory<ReceivableCollection>
 */
final class ReceivableCollectionFactory extends Factory
{
    protected $model = ReceivableCollection::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'customer_id' => (string) Str::uuid7(),
            'invoice_id' => (string) Str::uuid7(),
            'expected_amount' => 4500.00,
            'collected_amount' => 0.00,
            'due_date' => date('Y-m-d', strtotime('+30 days')),
            'collection_date' => null,
            'payment_method' => 'SEPA_DIRECT_DEBIT_CORE',
            'status' => 'PENDING',
            'aging_bucket' => 'CURRENT',
            'dunning_level' => 0,
        ];
    }

    public function overdue(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'OVERDUE',
            'due_date' => date('Y-m-d', strtotime('-45 days')),
            'aging_bucket' => '31_60',
            'dunning_level' => 1,
        ]);
    }

    public function collected(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'COLLECTED',
            'collected_amount' => $attributes['expected_amount'] ?? 4500.00,
            'collection_date' => date('Y-m-d'),
        ]);
    }
}
