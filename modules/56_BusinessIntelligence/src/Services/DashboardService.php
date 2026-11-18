<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use App\Models\User;
use App\Support\Database\TransactionLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Semantic\Dataset;
use Modules\Analytics\Semantic\Dimension;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;
use Modules\BusinessIntelligence\Models\BiSavedQuery;

/**
 * Dashboards and their widgets. A dashboard is a 12-column grid: a widget is a rectangle of it, rectangles never
 * overlap, a dashboard holds a limited number of widgets. A widget draws on a saved query or on its own specification of
 * the semantic layer; the filters of the dashboard (period and dimension filters) apply to every widget whose dataset has
 * the dimension, and a widget can be drilled down through the ordered dimensions of its drill path.
 */
final readonly class DashboardService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ReportEngine $engine,
        private BiQueryService $queries,
        private PivotService $pivots,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, User $user, array $data): BiDashboard
    {
        return BiDashboard::create([
            'tenant_id' => $tenantId,
            'owner_id' => $user->id,
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'visibility' => $data['visibility'] ?? BiSavedQuery::Private,
            'filters' => $this->cleanFilters($data['filters'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BiDashboard $dashboard, array $data): BiDashboard
    {
        $fields = array_intersect_key($data, array_flip(['name', 'description', 'visibility']));

        if (array_key_exists('filters', $data)) {
            $fields['filters'] = $this->cleanFilters($data['filters']);
        }

        $dashboard->update($fields);

        return $dashboard->refresh();
    }

    /**
     * Adds a widget. Without a layout it takes the first free place of the grid.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function addWidget(BiDashboard $dashboard, User $user, array $data): BiDashboardWidget
    {
        return DB::transaction(function () use ($dashboard, $user, $data): BiDashboardWidget {
            TransactionLock::acquire('bi-dashboard:'.$dashboard->id);
            $widgets = BiDashboardWidget::query()->where('dashboard_id', $dashboard->id)->get();

            if ($widgets->count() >= BiDashboardWidget::MaxWidgets) {
                throw ValidationException::withMessages(['widget_type' => [__('bi_errors.widget.limit', ['max' => BiDashboardWidget::MaxWidgets])]]);
            }

            $fields = $this->widgetFields($user, $data);
            $rectangles = $widgets->map(static fn (BiDashboardWidget $widget): array => $widget->rectangle())->all();
            $rectangle = isset($data['layout']['x'], $data['layout']['y'])
                ? $this->assertRectangle((array) $data['layout'], $rectangles)
                : $this->freeSlot((int) ($data['layout']['w'] ?? 6), (int) ($data['layout']['h'] ?? 4), $rectangles);

            return BiDashboardWidget::create([
                ...$fields,
                'tenant_id' => $dashboard->tenant_id,
                'dashboard_id' => $dashboard->id,
                'layout_x' => $rectangle['x'],
                'layout_y' => $rectangle['y'],
                'layout_w' => $rectangle['w'],
                'layout_h' => $rectangle['h'],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateWidget(BiDashboardWidget $widget, User $user, array $data): BiDashboardWidget
    {
        return DB::transaction(function () use ($widget, $user, $data): BiDashboardWidget {
            TransactionLock::acquire('bi-dashboard:'.$widget->dashboard_id);
            $merged = [...$widget->only(['title', 'widget_type', 'saved_query_id', 'dataset', 'spec', 'calculated_measures', 'config']), ...$data];
            $fields = $this->widgetFields($user, $merged);

            if (isset($data['layout'])) {
                $others = BiDashboardWidget::query()->where('dashboard_id', $widget->dashboard_id)->whereKeyNot($widget->id)->get()
                    ->map(static fn (BiDashboardWidget $other): array => $other->rectangle())->all();
                $rectangle = $this->assertRectangle((array) $data['layout'], $others);
                $fields = [...$fields, 'layout_x' => $rectangle['x'], 'layout_y' => $rectangle['y'], 'layout_w' => $rectangle['w'], 'layout_h' => $rectangle['h']];
            }

            $widget->update($fields);

            return $widget->refresh();
        });
    }

    /**
     * Moves and resizes several widgets at once (the dashboard builder saves the whole grid): the new grid is checked as
     * a whole, so widgets can swap places.
     *
     * @param  list<array{id: string, x: int, y: int, w: int, h: int}>  $layout
     * @return list<BiDashboardWidget>
     *
     * @throws ValidationException
     */
    public function relayout(BiDashboard $dashboard, array $layout): array
    {
        return DB::transaction(function () use ($dashboard, $layout): array {
            TransactionLock::acquire('bi-dashboard:'.$dashboard->id);
            $widgets = BiDashboardWidget::query()->where('dashboard_id', $dashboard->id)->get()->keyBy('id');
            $rectangles = [];

            foreach ($widgets as $id => $widget) {
                $rectangles[$id] = $widget->rectangle();
            }

            foreach ($layout as $index => $entry) {
                if (! $widgets->has($entry['id'])) {
                    throw ValidationException::withMessages(["layout.{$index}.id" => [__('bi_errors.widget.not_in_dashboard')]]);
                }

                $rectangles[$entry['id']] = ['x' => (int) $entry['x'], 'y' => (int) $entry['y'], 'w' => (int) $entry['w'], 'h' => (int) $entry['h']];
            }

            $checked = [];

            foreach ($rectangles as $id => $rectangle) {
                $this->assertRectangle($rectangle, array_values($checked), "layout.{$id}");
                $checked[$id] = $rectangle;
            }

            foreach ($layout as $entry) {
                $widgets[$entry['id']]->update(['layout_x' => (int) $entry['x'], 'layout_y' => (int) $entry['y'], 'layout_w' => (int) $entry['w'], 'layout_h' => (int) $entry['h']]);
            }

            return BiDashboardWidget::query()->where('dashboard_id', $dashboard->id)->orderBy('layout_y')->orderBy('layout_x')->get()->all();
        });
    }

    /**
     * The data of every widget. A widget that cannot be drawn (the viewer may not read its dataset, a saved query was
     * deleted) carries its error instead of failing the whole dashboard.
     *
     * @param  array<string, mixed>  $overrides  `period` and `filters` of the viewer, replacing the saved ones of the dashboard
     * @return list<array<string, mixed>>
     */
    public function data(BiDashboard $dashboard, User $user, array $overrides = []): array
    {
        $out = [];

        foreach ($dashboard->widgets()->get() as $widget) {
            try {
                $out[] = ['widget_id' => $widget->id, 'status' => 'ok', 'error' => null, ...$this->widgetData($dashboard, $widget, $user, $overrides)];
            } catch (ValidationException $exception) {
                $out[] = ['widget_id' => $widget->id, 'status' => 'error', 'error' => (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage()), 'result' => null, 'pivot' => null, 'drill' => null];
            } catch (AuthorizationException $exception) {
                $out[] = ['widget_id' => $widget->id, 'status' => 'error', 'error' => $exception->getMessage(), 'result' => null, 'pivot' => null, 'drill' => null];
            }
        }

        return $out;
    }

    /**
     * The data of one widget with the filters of the dashboard and, optionally, a drill-down.
     *
     * @param  array<string, mixed>  $overrides  `period`, `filters`, `drill` (list of `{dimension, value}`)
     * @return array{result: array<string, mixed>, pivot: array<string, mixed>|null, drill: array<string, mixed>}
     *
     * @throws ValidationException|AuthorizationException
     */
    public function widgetData(BiDashboard $dashboard, BiDashboardWidget $widget, User $user, array $overrides = []): array
    {
        [$datasetKey, $spec, $calculated, $pivotLayout] = $this->source($widget);
        $dataset = $this->engine->datasetFor($datasetKey, $user);
        $path = array_values(array_map('strval', (array) ($widget->config['drill_path'] ?? [])));
        $drill = array_values((array) ($overrides['drill'] ?? []));
        $drillFilters = $this->drillFilters($drill, $path);

        if ($drill !== []) {
            $spec['dimensions'] = [$path[count($drill)]];
            $pivotLayout = null;
        }

        $scope = $dashboard->filters ?? [];
        $period = $overrides['period'] ?? $scope['period'] ?? null;

        if ($period !== null && $dataset->periodDimension !== null) {
            $spec['period'] = $period;
        }

        $extra = [...$this->applicableFilters((array) ($overrides['filters'] ?? $scope['filters'] ?? []), $dataset), ...$drillFilters];
        $result = $this->queries->run($datasetKey, $spec, $calculated, $user, $extra, null, 'spec.');
        $pivot = $pivotLayout === null ? null : $this->pivots->build(
            $result,
            $this->pivots->validateLayout($pivotLayout, $this->dimensionKeys($spec), array_values(array_unique([...array_map('strval', (array) ($spec['measures'] ?? [])), ...$calculated]))),
            $this->queries->additiveMeasures($datasetKey, $user),
        );

        return [
            'result' => $result,
            'pivot' => $pivot,
            'drill' => [
                'path' => $path,
                'level' => count($drill),
                'values' => $drill,
                'next_dimension' => isset($path[count($drill) + 1]) ? $path[count($drill) + 1] : null,
                'current_dimension' => $path[count($drill)] ?? null,
            ],
        ];
    }

    /**
     * Dataset, specification, calculated measures and pivot of a widget, from its saved query or its own definition.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>, 3: array<string, mixed>|null}
     *
     * @throws ValidationException
     */
    private function source(BiDashboardWidget $widget): array
    {
        if ($widget->saved_query_id !== null) {
            $query = BiSavedQuery::query()->find($widget->saved_query_id)
                ?? throw ValidationException::withMessages(['saved_query_id' => [__('bi_errors.widget.saved_query_missing')]]);

            return [$query->dataset, $query->spec, $query->calculated_measures ?? [], $widget->widget_type === 'pivot' ? ($widget->config['pivot'] ?? $query->pivot) : null];
        }

        return [(string) $widget->dataset, (array) $widget->spec, $widget->calculated_measures ?? [], $widget->widget_type === 'pivot' ? ($widget->config['pivot'] ?? null) : null];
    }

    /**
     * @param  list<mixed>  $drill
     * @param  list<string>  $path
     * @return list<array{field: string, operator: string, value?: mixed}>
     *
     * @throws ValidationException
     */
    private function drillFilters(array $drill, array $path): array
    {
        if ($drill === []) {
            return [];
        }

        if (count($drill) > count($path) - 1) {
            throw ValidationException::withMessages(['drill' => [__('bi_errors.widget.drill_too_deep')]]);
        }

        $filters = [];

        foreach ($drill as $level => $step) {
            $dimension = is_array($step) ? ($step['dimension'] ?? null) : null;

            if ($dimension !== $path[$level]) {
                throw ValidationException::withMessages(["drill.{$level}.dimension" => [__('bi_errors.widget.drill_mismatch', ['expected' => $path[$level]])]]);
            }

            $value = $step['value'] ?? null;
            $filters[] = $value === null ? ['field' => $path[$level], 'operator' => 'is_null'] : ['field' => $path[$level], 'operator' => 'eq', 'value' => $value];
        }

        return $filters;
    }

    /**
     * The dashboard filters that apply to this dataset: the ones on a dimension it declares.
     *
     * @param  list<mixed>  $filters
     * @return list<array{field: string, operator: string, value?: mixed}>
     */
    private function applicableFilters(array $filters, Dataset $dataset): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $filter): array => (array) $filter, $filters),
            static fn (array $filter): bool => isset($filter['field']) && is_string($filter['field']) && $dataset->dimension($filter['field']) !== null,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function widgetFields(User $user, array $data): array
    {
        $type = (string) $data['widget_type'];
        $savedQueryId = $data['saved_query_id'] ?? null;
        $inline = ! empty($data['dataset']) || ! empty($data['spec']);

        if ($savedQueryId === null && ! $inline) {
            throw ValidationException::withMessages(['saved_query_id' => [__('bi_errors.widget.source_required')]]);
        }

        if ($savedQueryId !== null && $inline) {
            throw ValidationException::withMessages(['saved_query_id' => [__('bi_errors.widget.source_conflict')]]);
        }

        if ($savedQueryId !== null) {
            $query = BiSavedQuery::query()->visibleTo($user)->find($savedQueryId)
                ?? throw ValidationException::withMessages(['saved_query_id' => [__('bi_errors.widget.saved_query_missing')]]);
            $datasetKey = $query->dataset;
            $spec = null;
            $calculated = null;
        } else {
            $datasetKey = (string) $data['dataset'];
            $spec = (array) $data['spec'];
            $calculated = array_values(array_unique(array_map('strval', (array) ($data['calculated_measures'] ?? []))));
            $this->queries->validate($datasetKey, $spec, $calculated, $user);
        }

        $dataset = $this->engine->datasetFor($datasetKey, $user);
        $config = (array) ($data['config'] ?? []);
        $config['drill_path'] = $this->cleanDrillPath((array) ($config['drill_path'] ?? []), $dataset);

        if ($type === 'pivot' && isset($config['pivot'])) {
            $dimensions = $spec !== null ? $this->dimensionKeys($spec) : $this->dimensionKeys($query->spec ?? []);
            $measures = $spec !== null ? [...array_map('strval', (array) ($spec['measures'] ?? [])), ...$calculated] : [...array_map('strval', (array) ($query->spec['measures'] ?? [])), ...($query->calculated_measures ?? [])];
            $config['pivot'] = $this->pivots->validateLayout((array) $config['pivot'], $dimensions, $measures, 'config.pivot.');
        }

        return [
            'title' => (string) $data['title'],
            'widget_type' => $type,
            'saved_query_id' => $savedQueryId,
            'dataset' => $savedQueryId === null ? $datasetKey : null,
            'spec' => $spec,
            'calculated_measures' => $calculated,
            'config' => $config,
        ];
    }

    /**
     * The drill path may only name dimensions of the dataset that are not dates (a date bucket has no single value to
     * filter on).
     *
     * @param  list<mixed>  $path
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function cleanDrillPath(array $path, Dataset $dataset): array
    {
        $clean = [];

        foreach ($path as $index => $key) {
            $dimension = is_string($key) ? $dataset->dimension($key) : null;

            if ($dimension === null) {
                throw ValidationException::withMessages(["config.drill_path.{$index}" => [__('bi_errors.widget.drill_path_unknown')]]);
            }

            if ($dimension->type === Dimension::TypeDate) {
                throw ValidationException::withMessages(["config.drill_path.{$index}" => [__('bi_errors.widget.drill_path_date', ['key' => $dimension->key])]]);
            }

            if (in_array($dimension->key, $clean, true)) {
                throw ValidationException::withMessages(["config.drill_path.{$index}" => [__('bi_errors.widget.drill_path_repeated', ['key' => $dimension->key])]]);
            }

            $clean[] = $dimension->key;
        }

        return $clean;
    }

    /**
     * Shape check of the filters of a dashboard; the fields are validated against each dataset when a widget runs.
     *
     * @return array{period?: array<string, string>, filters?: list<array<string, mixed>>}|null
     *
     * @throws ValidationException
     */
    private function cleanFilters(mixed $filters): ?array
    {
        if (empty($filters)) {
            return null;
        }

        $clean = [];

        if (isset($filters['period'])) {
            $clean['period'] = (array) $filters['period'];
        }

        if (! empty($filters['filters'])) {
            $clean['filters'] = array_values(array_map(static fn (mixed $filter): array => array_intersect_key((array) $filter, array_flip(['field', 'operator', 'value'])), (array) $filters['filters']));
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function dimensionKeys(array $spec): array
    {
        return array_values(array_map(static fn (mixed $entry): string => explode(':', (string) $entry)[0], (array) ($spec['dimensions'] ?? [])));
    }

    /**
     * Checks a rectangle against the grid and against the other rectangles.
     *
     * @param  array<string, mixed>  $layout
     * @param  list<array{x: int, y: int, w: int, h: int}>  $others
     * @return array{x: int, y: int, w: int, h: int}
     *
     * @throws ValidationException
     */
    private function assertRectangle(array $layout, array $others, string $field = 'layout'): array
    {
        $rectangle = ['x' => (int) ($layout['x'] ?? 0), 'y' => (int) ($layout['y'] ?? 0), 'w' => (int) ($layout['w'] ?? 6), 'h' => (int) ($layout['h'] ?? 4)];

        if ($rectangle['x'] < 0 || $rectangle['y'] < 0 || $rectangle['w'] < 1 || $rectangle['h'] < 1 || $rectangle['x'] + $rectangle['w'] > BiDashboardWidget::GridColumns) {
            throw ValidationException::withMessages([$field => [__('bi_errors.widget.grid_bounds', ['columns' => BiDashboardWidget::GridColumns])]]);
        }

        foreach ($others as $other) {
            if (BiDashboardWidget::overlap($rectangle, $other)) {
                throw ValidationException::withMessages([$field => [__('bi_errors.widget.overlap')]]);
            }
        }

        return $rectangle;
    }

    /**
     * The first free place of the grid for a rectangle of the given size, scanning rows from the top.
     *
     * @param  list<array{x: int, y: int, w: int, h: int}>  $others
     * @return array{x: int, y: int, w: int, h: int}
     */
    private function freeSlot(int $width, int $height, array $others): array
    {
        $width = max(1, min($width, BiDashboardWidget::GridColumns));
        $height = max(1, $height);
        $bottom = 0;

        foreach ($others as $other) {
            $bottom = max($bottom, $other['y'] + $other['h']);
        }

        for ($y = 0; $y <= $bottom; $y++) {
            for ($x = 0; $x + $width <= BiDashboardWidget::GridColumns; $x++) {
                $candidate = ['x' => $x, 'y' => $y, 'w' => $width, 'h' => $height];

                if (array_filter($others, static fn (array $other): bool => BiDashboardWidget::overlap($candidate, $other)) === []) {
                    return $candidate;
                }
            }
        }

        return ['x' => 0, 'y' => $bottom, 'w' => $width, 'h' => $height];
    }
}
