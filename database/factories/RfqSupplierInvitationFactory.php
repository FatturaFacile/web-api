<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;

/**
 * @extends Factory<RfqSupplierInvitation>
 */
final class RfqSupplierInvitationFactory extends Factory
{
    protected $model = RfqSupplierInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'rfq_id' => RequestForQuotation::factory(),
            'supplier_id' => Supplier::factory(),
            'status' => InvitationStatus::Pending->value,
            'access_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'token_expires_at' => now('UTC')->addDays(7),
        ];
    }
}
