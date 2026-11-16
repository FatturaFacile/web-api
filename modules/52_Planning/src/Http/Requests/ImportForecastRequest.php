<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

/**
 * Import of the forecasts into the demand side of a draft scenario.
 */
final class ImportForecastRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['overwrite' => ['sometimes', 'boolean']];
    }
}
