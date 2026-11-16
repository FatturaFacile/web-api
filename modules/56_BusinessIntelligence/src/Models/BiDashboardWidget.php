<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use Database\Factories\BiDashboardWidgetFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A widget of a dashboard. Its data comes from a saved query or from an inline dataset and specification of the
 * semantic layer. Its place is a rectangle of the 12-column grid of the dashboard; rectangles never overlap.
 *
 * `config` keys: `drill_path` (ordered list of non-date dimension keys the user can drill through).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dashboard_id
 * @property string|null $saved_query_id
 * @property string $title
 * @property string $widget_type
 * @property string|null $dataset
 * @property array<string, mixed>|null $spec
 * @property list<string>|null $calculated_measures
 * @property array<string, mixed>|null $config
 * @property int $layout_x
 * @property int $layout_y
 * @property int $layout_w
 * @property int $layout_h
 */
#[UseFactory(BiDashboardWidgetFactory::class)]
final class BiDashboardWidget extends BaseEnterpriseModel
{
    public const int GridColumns = 12;

    public const int MaxWidgets = 30;

    /** @var list<string> */
    public const array Types = ['kpi', 'table', 'bar', 'line', 'pie', 'pivot'];

    protected $table = 'bi_dashboard_widgets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'calculated_measures' => 'array',
            'config' => 'array',
            'layout_x' => 'integer',
            'layout_y' => 'integer',
            'layout_w' => 'integer',
            'layout_h' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BiDashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(BiDashboard::class, 'dashboard_id');
    }

    /**
     * @return BelongsTo<BiSavedQuery, $this>
     */
    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(BiSavedQuery::class, 'saved_query_id');
    }

    /**
     * The rectangle of the widget on the grid.
     *
     * @return array{x: int, y: int, w: int, h: int}
     */
    public function rectangle(): array
    {
        return ['x' => $this->layout_x, 'y' => $this->layout_y, 'w' => $this->layout_w, 'h' => $this->layout_h];
    }

    /**
     * True when two rectangles of the grid share at least one cell.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $a
     * @param  array{x: int, y: int, w: int, h: int}  $b
     */
    public static function overlap(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w'] && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    }
}
