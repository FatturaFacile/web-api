<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of the read endpoints: the snapshot date, the timeline horizon and approach, the locale of the documents and the
 * parameters of the Rangrücktritt calculator.
 */
final class CrisisQueryRequest extends FormRequest
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
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'horizon' => ['nullable', Rule::in([6, 12])],
            'approach' => ['nullable', Rule::in(['treasury', 'operating'])],
            'locale' => ['nullable', Rule::in(['it', 'en', 'de', 'fr'])],
            'horizon_months' => ['nullable', 'integer', 'between:1,60'],
            'interest_rate' => ['nullable', 'numeric', 'between:0,100'],
            'basis' => ['nullable', Rule::in(['going_concern', 'liquidation'])],
        ];
    }

    public function asOf(): ?CarbonImmutable
    {
        $date = $this->validated('as_of');

        return $date === null ? null : CarbonImmutable::parse((string) $date);
    }
}
