<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Enums\PaymentMethod;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<FeePayment>
 */
final class FeePaymentFactory extends Factory
{
    protected $model = FeePayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'fee_instalment_id' => static fn (array $attributes): string => FeeInstalmentFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'fee_assignment_id' => FeeAssignmentFactory::new()->create(['tenant_id' => $attributes['tenant_id'], 'member_id' => $attributes['member_id']])->id,
                'paid_amount' => '100.00',
                'status' => InstalmentStatus::Paid,
            ])->id,
            'amount' => '100.00',
            'paid_on' => '2026-10-15',
            'method' => PaymentMethod::BankTransfer,
            'reference' => 'CRO '.fake()->numerify('###########'),
            'receipt_id' => null,
        ];
    }
}
