<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Domain\Enums;

enum WidgetType: string
{
    case MetricCard = 'metric_card';
    case WaterfallChart = 'waterfall_chart';
    case AreaTrend = 'area_trend';
    case Funnel = 'funnel';
    case Heatmap = 'heatmap';
    case TopNTable = 'top_n_table';
    case Gauge = 'gauge';
}
