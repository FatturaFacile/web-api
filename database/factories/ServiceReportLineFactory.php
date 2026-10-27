<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Models\ServiceReportLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceReportLine>
 */
class ServiceReportLineFactory extends Factory
{
    protected $model = ServiceReportLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $report = ServiceReport::first() ?? ServiceReport::factory()->create();

        $laborType = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_line_type'))
            ->where('code', 'labor')
            ->first();

        return [
            'tenant_id' => $report->tenant_id,
            'service_report_id' => $report->id,
            'line_type_id' => $laborType?->id ?? LookupItem::first()?->id,
            'description' => 'Diagnostic and wiring inspection',
            'quantity' => 2.5,
            'unit_of_measure' => 'hours',
            'unit_price' => 60.00,
            'discount_percent' => 0.0,
            'tax_rate' => 22.0,
            'line_net' => 150.00,
            'line_tax' => 33.00,
            'line_total' => 183.00,
            'is_billable' => true,
        ];
    }
}
