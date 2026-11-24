<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSession;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\RetailSessionReport;

/**
 * @extends Factory<RetailSessionReport>
 */
final class RetailSessionReportFactory extends Factory
{
    protected $model = RetailSessionReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pos_session_id' => PosSession::factory(),
            'kind' => 'x',
            'number' => null,
            'business_date' => now()->toDateString(),
            'totals' => ['sales_count' => 0],
        ];
    }
}
