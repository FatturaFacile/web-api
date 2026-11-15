<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Returns\Models\Rma;

/**
 * The resolution of an inspected return: refund, credit note reference, replacement or repair.
 */
final class RmaResolveRequest extends FormRequest
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
            'resolution_type' => ['required', Rule::in(Rma::ResolutionTypes)],
            'refund_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'credit_note_reference' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
