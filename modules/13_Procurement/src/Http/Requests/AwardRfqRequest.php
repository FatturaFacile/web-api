<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The winning quote of a request for quotation.
 */
final class AwardRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'supplier_quote_id' => ['required', 'uuid'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
