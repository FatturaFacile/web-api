<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Services\Dashboard\WidgetRegistry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Replaces the dashboard of the current user: which widgets, where they sit on the 12 column grid and how each one is
 * configured.
 */
class UpdateDashboardLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $registry = app(WidgetRegistry::class);

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'widgets_layout' => ['present', 'array', 'max:'.count($registry->ids())],
            'widgets_layout.*' => ['array'],
            'widgets_layout.*.widget_id' => [
                'required', 'string', 'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($registry): void {
                    if (! $registry->has((string) $value)) {
                        $fail(__('dashboard.errors.unknown_widget', ['widget' => $value]));
                    }
                },
            ],
            'widgets_layout.*.x' => ['required', 'integer', 'min:0', 'max:'.(WidgetRegistry::GRID_COLUMNS - 1)],
            'widgets_layout.*.y' => ['required', 'integer', 'min:0', 'max:1000'],
            'widgets_layout.*.w' => ['required', 'integer', 'min:1', 'max:'.WidgetRegistry::GRID_COLUMNS],
            'widgets_layout.*.h' => ['required', 'integer', 'min:1', 'max:50'],
            'widgets_layout.*.settings' => ['nullable', 'array'],
        ];
    }

    /**
     * Widgets must fit in the grid and their settings must be valid for the widget.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $registry = app(WidgetRegistry::class);

                foreach ((array) $this->input('widgets_layout', []) as $index => $entry) {
                    if (! is_array($entry) || ! $registry->has((string) ($entry['widget_id'] ?? ''))) {
                        continue;
                    }

                    if ($validator->errors()->has("widgets_layout.{$index}.x") || $validator->errors()->has("widgets_layout.{$index}.w")) {
                        continue;
                    }

                    if ((int) $entry['x'] + (int) $entry['w'] > WidgetRegistry::GRID_COLUMNS) {
                        $validator->errors()->add("widgets_layout.{$index}.w", __('dashboard.errors.exceeds_grid', ['columns' => WidgetRegistry::GRID_COLUMNS]));
                    }

                    foreach ($registry->get($entry['widget_id'])->validateSettings((array) ($entry['settings'] ?? [])) as $option => $message) {
                        $validator->errors()->add("widgets_layout.{$index}.settings.{$option}", $message);
                    }
                }
            },
        ];
    }

    /**
     * The layout entries with only the known keys and integer coordinates.
     *
     * @return array<int, array{widget_id: string, x: int, y: int, w: int, h: int, settings: array<string, mixed>}>
     */
    public function layout(): array
    {
        return array_map(fn (array $entry): array => [
            'widget_id' => (string) $entry['widget_id'],
            'x' => (int) $entry['x'],
            'y' => (int) $entry['y'],
            'w' => (int) $entry['w'],
            'h' => (int) $entry['h'],
            'settings' => (array) ($entry['settings'] ?? []),
        ], array_values((array) $this->input('widgets_layout')));
    }
}
