<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Authorization is enforced per route by the logistics.can middleware (RBAC matrix). */
final class ReceiveTransferOrderRequest extends FormRequest
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
            'final' => ['sometimes', 'boolean'],
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.received_qty' => ['required_without:lines.*.damaged_qty', 'nullable', 'numeric', 'min:0'],
            'lines.*.damaged_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
