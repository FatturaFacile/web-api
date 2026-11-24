<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;

/**
 * @extends Factory<BiDashboardWidget>
 */
final class BiDashboardWidgetFactory extends Factory
{
    protected $model = BiDashboardWidget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'dashboard_id' => BiDashboard::factory(),
            'title' => 'Net sales',
            'widget_type' => 'table',
            'dataset' => 'sales',
            'spec' => ['dimensions' => ['customer'], 'measures' => ['net_amount']],
            'calculated_measures' => [],
            'config' => ['drill_path' => []],
            'layout_x' => 0,
            'layout_y' => 0,
            'layout_w' => 6,
            'layout_h' => 4,
        ];
    }
}
