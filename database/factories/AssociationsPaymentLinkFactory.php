<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\PaymentLink;

/**
 * @extends Factory<PaymentLink>
 */
final class AssociationsPaymentLinkFactory extends Factory
{
    protected $model = PaymentLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'fee_instalment_id' => static function (array $attributes): string {
                $member = Member::factory()->create(['tenant_id' => $attributes['tenant_id']]);

                $assignment = FeeAssignment::create([
                    'tenant_id' => $attributes['tenant_id'],
                    'member_id' => $member->id,
                    'payer_contact_id' => $member->contact_id,
                    'description' => 'Quota annuale 2026',
                    'starts_on' => '2026-01-01',
                    'ends_on' => '2026-12-31',
                    'gross_amount' => '150.00',
                    'discount_amount' => '0.00',
                    'net_amount' => '150.00',
                    'status' => FeeAssignment::Active,
                ]);

                return FeeInstalment::create([
                    'tenant_id' => $attributes['tenant_id'],
                    'fee_assignment_id' => $assignment->id,
                    'sequence' => 1,
                    'due_on' => '2026-12-15',
                    'amount' => '150.00',
                    'paid_amount' => '0.00',
                    'status' => InstalmentStatus::Open,
                ])->id;
            },
            'provider' => 'local',
            'provider_reference' => static fn (): string => 'local_'.Str::lower((string) Str::ulid()),
            'amount' => '150.00',
            'status' => PaymentLink::Pending,
            'expires_at' => '2026-12-31 23:59:59',
            'paid_at' => null,
        ];
    }
}
