<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

/**
 * The new grid of a dashboard: one rectangle per widget that moved or changed size.
 */
final class LayoutRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'layout' => ['required', 'array', 'min:1', 'max:30'],
            'layout.*.id' => ['required', 'uuid', 'distinct', $this->ownRow('bi_dashboard_widgets')],
            'layout.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'layout.*.y' => ['required', 'integer', 'min:0', 'max:1000'],
            'layout.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'layout.*.h' => ['required', 'integer', 'min:1', 'max:40'],
        ];
    }
}
