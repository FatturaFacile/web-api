<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseRunRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
