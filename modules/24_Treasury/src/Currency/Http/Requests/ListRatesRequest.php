<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Currency\Domain\Enums\RateType;

final class ListRatesRequest extends FormRequest
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
            'base' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'quote' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'rate_type' => ['sometimes', 'nullable', 'string', Rule::in(RateType::values())],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
