<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateDashboardLayoutRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DashboardLayout;
use App\Services\Dashboard\WidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The customisable home dashboard: available widgets, the layout of the current user and the widget data.
 */
class DashboardBuilderController extends Controller
{
    use ApiResponse;

    private const string DEFAULT_NAME = 'Default Dashboard';

    public function __construct(
        private readonly WidgetRegistry $registry,
    )
    {
    }

    public function availableWidgets(): JsonResponse
    {
        $widgets = collect($this->registry->all())->map(fn($widget): array => [
            'id' => $widget->getId(),
            'title' => $widget->getTitle(),
            'description' => __("dashboard.widgets.{$widget->getId()}.description"),
            'type' => $widget->getType(),
            'default_dimensions' => $widget->getDefaultDimensions(),
            'configurable_options' => $widget->getConfigurableOptions(),
        ])->values()->all();

        return $this->successResponse($widgets, __('dashboard.messages.widgets_retrieved'));
    }

    /**
     * The saved layout of the user, or the factory layout when nothing has been saved yet.
     */
    public function showLayout(Request $request): JsonResponse
    {
        return $this->successResponse($this->present($this->savedLayout($request)), __('dashboard.messages.layout_retrieved'));
    }

    public function updateLayout(UpdateDashboardLayoutRequest $request): JsonResponse
    {
        $saved = $this->savedLayout($request);

        $layout = DashboardLayout::updateOrCreate(
            ['user_id' => $request->user()->id, 'is_default' => true],
            ['name' => $request->input('name', $saved?->name ?? self::DEFAULT_NAME), 'widgets_layout' => $request->layout()],
        );

        return $this->successResponse($this->present($layout), __('dashboard.messages.layout_updated'));
    }

    /**
     * Factory reset: the saved customisation is removed and the factory layout applies again.
     */
    public function resetLayout(Request $request): JsonResponse
    {
        DashboardLayout::query()->where('user_id', $request->user()->id)->delete();

        return $this->successResponse($this->present(null), __('dashboard.messages.layout_reset'));
    }

    /**
     * Data of the widgets of the current layout, or of the widgets named in `?widgets=a,b`.
     */
    public function data(Request $request): JsonResponse
    {
        $layout = $this->present($this->savedLayout($request))['widgets_layout'];
        $settings = collect($layout)->pluck('settings', 'widget_id');

        $requested = array_values(array_filter(array_map('trim', explode(',', (string)$request->query('widgets', '')))));

        foreach ($requested as $id) {
            if (!$this->registry->has($id)) {
                throw ValidationException::withMessages(['widgets' => __('dashboard.errors.unknown_widget', ['widget' => $id])]);
            }
        }

        $ids = $requested !== [] ? array_values(array_unique($requested)) : array_column($layout, 'widget_id');

        $widgets = array_map(function (string $id) use ($settings): array {
            $widget = $this->registry->get($id);
            $widgetSettings = (array)($settings[$id] ?? []);
            $result = ['id' => $id, 'title' => $widget->getTitle(), 'type' => $widget->getType(), 'settings' => $widgetSettings];

            try {
                return $result + ['data' => $widget->getData($widgetSettings)];
            } catch (Throwable $e) {
                // One broken widget must not take the whole dashboard down.
                Log::error('Dashboard widget {widget} failed: {message}', ['widget' => $id, 'message' => $e->getMessage()]);

                return $result + ['data' => null, 'error' => __('dashboard.errors.widget_failed')];
            }
        }, $ids);

        return $this->successResponse(['widgets' => $widgets, 'generated_at' => now()->toIso8601String()], __('dashboard.messages.data_retrieved'));
    }

    private function savedLayout(Request $request): ?DashboardLayout
    {
        return DashboardLayout::query()->where('user_id', $request->user()->id)->where('is_default', true)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?DashboardLayout $layout): array
    {
        if ($layout === null) {
            return [
                'id' => null,
                'name' => self::DEFAULT_NAME,
                'is_default' => true,
                'is_customized' => false,
                'widgets_layout' => $this->registry->getDefaultLayout(),
            ];
        }

        return [
            'id' => $layout->id,
            'name' => $layout->name,
            'is_default' => $layout->is_default,
            'is_customized' => true,
            // Widgets that are no longer registered are left out.
            'widgets_layout' => array_values(array_filter($layout->widgets_layout, fn(array $entry): bool => $this->registry->has($entry['widget_id']))),
        ];
    }
}
