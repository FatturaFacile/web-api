<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Procurement\Rfq\Enums\BidStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;

/**
 * @extends Factory<SupplierBid>
 */
final class SupplierBidFactory extends Factory
{
    protected $model = SupplierBid::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'rfq_id' => RequestForQuotation::factory(),
            'supplier_id' => Supplier::factory(),
            'quote_reference' => 'Q-'.fake()->numerify('#####'),
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'currency' => 'EUR',
            'exchange_rate_to_rfq' => '1.000000',
            'payment_terms_days' => 30,
            'incoterms' => 'DDP',
            'shipping_cost' => '0.0000',
            'lead_time_days' => 7,
            'status' => BidStatus::Submitted->value,
            'total_amount' => '100.00',
            'total_net_amount' => '100.0000',
            'total_landed_cost' => '100.0000',
            'submitted_ip_address' => '127.0.0.1',
            'submitted_user_agent' => 'PestTest/1.0',
            'submitted_at' => now('UTC'),
        ];
    }
}
