<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Models\EsgSupplierDataRequest;

/**
 * @extends Factory<EsgSupplierDataRequest>
 */
final class EsgSupplierDataRequestFactory extends Factory
{
    protected $model = EsgSupplierDataRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'reporting_period_id' => EsgReportingPeriod::factory(),
            'supplier_id' => (string) Str::uuid7(),
            'scope3_category' => 1,
            'activity_type' => 'purchased_goods',
            'unit' => 'EUR',
            'quantity' => '10000.0000',
            'status' => EsgSupplierDataRequest::Requested,
        ];
    }
}
