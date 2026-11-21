<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\AnalyticsDss\Application\Handlers\GetProfitabilityMatrixHandler;

/**
 * Filter and dimension of the profitability matrix.
 */
final class ProfitabilityMatrixRequest extends DssFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'dimension' => ['nullable', Rule::in(GetProfitabilityMatrixHandler::Dimensions)],
            'limit' => ['nullable', 'integer', 'between:1,500'],
        ];
    }
}
