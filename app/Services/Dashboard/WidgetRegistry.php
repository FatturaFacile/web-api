<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Contracts\Dashboard\DashboardWidgetInterface;
use App\Services\Dashboard\Widgets\CashFlowTrendWidget;
use App\Services\Dashboard\Widgets\EcommerceSyncStatusWidget;
use App\Services\Dashboard\Widgets\LowStockWidget;
use App\Services\Dashboard\Widgets\OverdueInvoicesWidget;
use App\Services\Dashboard\Widgets\RevenueKpiWidget;
use App\Services\Dashboard\Widgets\TopSellingProductsWidget;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The widgets available on the home dashboard and the factory layout that places them on the 12 column grid.
 */
class WidgetRegistry
{
    public const GRID_COLUMNS = 12;

    /**
     * Widgets shipped with the application.
     *
     * @var array<int, class-string<DashboardWidgetInterface>>
     */
    private const BUILT_IN = [
        RevenueKpiWidget::class,
        LowStockWidget::class,
        EcommerceSyncStatusWidget::class,
        CashFlowTrendWidget::class,
        TopSellingProductsWidget::class,
        OverdueInvoicesWidget::class,
    ];

    /**
     * Factory positions (x, y) of the built-in widgets; the size comes from the widget itself.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const DEFAULT_POSITIONS = [
        'revenue_kpi' => [0, 0],
        'low_stock_alert' => [4, 0],
        'ecommerce_sync_status' => [8, 0],
        'cash_flow_trend' => [0, 3],
        'top_selling_products' => [8, 3],
        'overdue_invoices' => [0, 7],
    ];

    /**
     * @var array<string, DashboardWidgetInterface>
     */
    private array $widgets = [];

    public function __construct(Container $container)
    {
        foreach (self::BUILT_IN as $class) {
            $this->register($container->make($class));
        }
    }

    /**
     * Make a widget available; registering an id again replaces the previous widget.
     */
    public function register(DashboardWidgetInterface $widget): void
    {
        $this->widgets[$widget->getId()] = $widget;
    }

    /**
     * @return array<string, DashboardWidgetInterface>
     */
    public function all(): array
    {
        return $this->widgets;
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_keys($this->widgets);
    }

    public function has(string $id): bool
    {
        return isset($this->widgets[$id]);
    }

    /**
     * @throws InvalidArgumentException when the widget is not registered
     */
    public function get(string $id): DashboardWidgetInterface
    {
        return $this->widgets[$id] ?? throw new InvalidArgumentException("Unknown dashboard widget [{$id}].");
    }

    /**
     * The factory layout: every registered widget at its default position and size, without custom settings.
     *
     * @return array<int, array{widget_id: string, x: int, y: int, w: int, h: int, settings: array<string, mixed>}>
     */
    public function getDefaultLayout(): array
    {
        $layout = [];
        $bottom = 0;

        foreach (self::DEFAULT_POSITIONS as $id => [$x, $y]) {
            if (isset($this->widgets[$id])) {
                $layout[] = $this->placement($this->widgets[$id], $x, $y);
                $bottom = max($bottom, $y + $this->widgets[$id]->getDefaultDimensions()['h']);
            }
        }

        // Widgets registered by extensions go below the built-in ones.
        foreach ($this->widgets as $id => $widget) {
            if (! isset(self::DEFAULT_POSITIONS[$id])) {
                $layout[] = $this->placement($widget, 0, $bottom);
                $bottom += $widget->getDefaultDimensions()['h'];
            }
        }

        return $layout;
    }

    /**
     * @return array{widget_id: string, x: int, y: int, w: int, h: int, settings: array<string, mixed>}
     */
    private function placement(DashboardWidgetInterface $widget, int $x, int $y): array
    {
        return ['widget_id' => $widget->getId(), 'x' => $x, 'y' => $y, ...$widget->getDefaultDimensions(), 'settings' => []];
    }
}
