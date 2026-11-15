<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer return: the customer, the source document, the reason and the lines taken from the source lines.
 */
final class RmaRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lines = [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.invoice_item_id' => ['nullable', 'uuid', 'required_without:lines.*.sales_order_item_id'],
            'lines.*.sales_order_item_id' => ['nullable', 'uuid', 'required_without:lines.*.invoice_item_id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.reason_code' => ['nullable', 'string', 'max:40'],
        ];

        if ($this->isMethod('PUT')) {
            return [
                'reason_code' => ['sometimes', 'required', 'string', 'max:40'],
                'requested_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'override_window' => ['sometimes', 'boolean'],
                'notes' => ['nullable', 'string', 'max:2000'],
                ...array_map(static fn (array $rule): array => ['sometimes', ...$rule], $lines),
            ];
        }

        return [
            'contact_id' => ['required', 'uuid'],
            'invoice_id' => ['nullable', 'uuid', 'required_without:sales_order_id'],
            'sales_order_id' => ['nullable', 'uuid', 'required_without:invoice_id'],
            'reason_code' => ['required', 'string', 'max:40'],
            'requested_on' => ['nullable', 'date_format:Y-m-d'],
            'override_window' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$lines,
        ];
    }
}
