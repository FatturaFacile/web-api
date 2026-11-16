<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The goods of a return arrive: the quantity received per line (every line in full when omitted) and the warehouse.
 */
final class RmaReceiveRequest extends FormRequest
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
        return [
            'warehouse_id' => ['nullable', 'uuid'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
