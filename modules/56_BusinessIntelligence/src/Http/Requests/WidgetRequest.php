<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;

/**
 * A widget of a dashboard: its source (a saved query or an inline specification), type, options and place on the grid.
 */
final class WidgetRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'title' => ['required', 'string', 'max:160'],
                'widget_type' => ['required', Rule::in(BiDashboardWidget::Types)],
            ]),
            'saved_query_id' => ['nullable', 'uuid', $this->ownRow('bi_saved_queries')],
            'dataset' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'spec' => ['nullable', 'array'],
            'calculated_measures' => ['nullable', 'array', 'max:8'],
            'calculated_measures.*' => ['string', 'max:41'],
            'config' => ['nullable', 'array'],
            'config.drill_path' => ['nullable', 'array', 'max:6'],
            'config.drill_path.*' => ['string', 'max:41'],
            ...$this->pivotRules('config.pivot'),
            'layout' => ['nullable', 'array'],
            'layout.x' => ['nullable', 'integer', 'min:0', 'max:11'],
            'layout.y' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'layout.w' => ['nullable', 'integer', 'min:1', 'max:12'],
            'layout.h' => ['nullable', 'integer', 'min:1', 'max:40'],
        ];
    }
}
