<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Open purchase suggestions to turn into draft purchase orders (one order per supplier).
 */
final class ConvertPurchaseSuggestionsRequest extends FormRequest
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
            'suggestion_ids' => ['required', 'array', 'min:1'],
            'suggestion_ids.*' => ['uuid'],
        ];
    }
}
