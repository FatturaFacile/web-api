<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Currency\Domain\Enums\RevaluationPeriodType;

final class SimulateRevaluationRequest extends FormRequest
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
        return [
            'period_end' => ['required', 'date_format:Y-m-d'],
            'period_type' => ['required', 'string', Rule::enum(RevaluationPeriodType::class)],
            'currencies' => ['sometimes', 'nullable', 'array'],
            'currencies.*' => ['string', 'size:3', 'alpha'],
        ];
    }
}
