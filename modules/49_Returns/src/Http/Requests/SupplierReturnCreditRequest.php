<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The credit the supplier granted for a shipped return.
 */
final class SupplierReturnCreditRequest extends FormRequest
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
        return ['credit_received' => ['required', 'numeric', 'min:0', 'max:9999999999']];
    }
}
