<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Currency\Domain\Enums\RateType;

final class ResolveRateRequest extends FormRequest
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
            'base' => ['required', 'string', 'size:3', 'alpha'],
            'quote' => ['required', 'string', 'size:3', 'alpha'],
            'date' => ['required', 'date_format:Y-m-d'],
            'rate_type' => ['sometimes', 'string', Rule::in(RateType::values())],
            'contract_reference' => ['nullable', 'string', 'max:80'],
        ];
    }
}
