<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupplierBidLine>
 */
final class SupplierBidLineFactory extends Factory
{
    protected $model = SupplierBidLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'supplier_quote_id' => SupplierBid::factory(),
            'rfq_line_id' => RfqLine::factory(),
            'is_no_bid' => false,
            'quantity' => '10.0000',
            'unit_price' => '20.0000',
            'discount_percent' => '0.00',
            'net_unit_price' => '20.0000',
            'tax_rate' => '22.00',
            'line_total' => '200.00',
            'line_total_net' => '200.0000',
            'lead_time_days' => 7,
            'is_awarded' => false,
        ];
    }
}
