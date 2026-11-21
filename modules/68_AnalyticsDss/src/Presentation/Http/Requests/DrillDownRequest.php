<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\AnalyticsDss\Application\Handlers\GetDssDrillDownHandler;

/**
 * Filter and record selector of a drill-down.
 */
final class DrillDownRequest extends DssFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'metric' => ['required', Rule::in(GetDssDrillDownHandler::Metrics)],
            'entity_type' => ['nullable', 'required_with:entity_id', Rule::in(GetDssDrillDownHandler::EntityTypes)],
            'entity_id' => ['nullable', 'required_with:entity_type', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }
}
