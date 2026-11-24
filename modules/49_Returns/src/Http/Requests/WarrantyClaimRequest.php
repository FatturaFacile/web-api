<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A claim on a warranty, optionally linked to the return that brings the goods back.
 */
final class WarrantyClaimRequest extends FormRequest
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
            'warranty_id' => ['required', 'uuid'],
            'rma_id' => ['nullable', 'uuid'],
            'claim_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:4000'],
        ];
    }
}
