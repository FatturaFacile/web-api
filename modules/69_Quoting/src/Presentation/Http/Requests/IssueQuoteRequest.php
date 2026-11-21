<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IssueQuoteRequest extends FormRequest
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
            'validity_days' => ['nullable', 'integer', 'between:1,365'],
            'use_pin' => ['sometimes', 'boolean'],
        ];
    }
}
