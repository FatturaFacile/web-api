<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignPpeRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'lot_or_serial_id' => ['nullable', 'string', 'max:96'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'ce_marking_standard' => ['nullable', 'string', 'max:96'],
            'handed_over_at' => ['nullable', 'date'],
            'replacement_due_at' => ['nullable', 'date'],
            'signature_payload' => ['required', 'string', 'max:200000'],
            'signature_image' => ['nullable', 'string', 'max:2000000'],
            'locale' => ['nullable', 'string', 'in:it-CH,de-CH,fr-CH,it-IT,en,it,de,fr'],
        ];
    }
}
