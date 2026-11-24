<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The emission data a supplier sent for a request.
 */
final class ReceiveSupplierDataRequest extends FormRequest
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
            'reported_co2e_kg' => ['required', 'numeric', 'gt:0', 'max:999999999999999999'],
            'calculation_method' => ['nullable', 'string', 'max:190'],
            'submission_id' => ['nullable', 'uuid'],
        ];
    }
}
