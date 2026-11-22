<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Support\BillingStatuses;

/**
 * Moves the billing contract of a project to another status (Mod 081).
 */
final class ChangeBillingContractStatusRequest extends FormRequest
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
            'billing_status' => ['required', 'string', Rule::in(BillingStatuses::ContractStatuses)],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
