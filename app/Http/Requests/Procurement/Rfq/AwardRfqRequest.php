<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates Full Award, Split Award, or legacy single-quote Award payloads.
 */
final class AwardRfqRequest extends FormRequest
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
            'award_mode' => ['nullable', 'string', 'in:full,split'],
            'supplier_id' => ['nullable', 'uuid'],
            'bid_id' => ['nullable', 'uuid'],
            'supplier_quote_id' => ['nullable', 'uuid'],
            'line_awards' => ['required_if:award_mode,split', 'array', 'min:1'],
            'line_awards.*.rfq_line_id' => ['required', 'uuid'],
            'line_awards.*.supplier_bid_line_id' => ['required', 'uuid'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
