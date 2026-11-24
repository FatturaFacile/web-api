<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Treasury\Rules\ValidIban;

/**
 * A bank detail change request: entered by the staff (supplier_id required) or sent by the supplier from the portal
 * (the supplier is the one of the token).
 */
final class BankChangeSubmitRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware, or by the portal token.
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
            'supplier_id' => [$this->attributes->has('portalSupplier') ? 'prohibited' : 'required', 'uuid', $this->tenantOwns('suppliers')],
            'account_holder' => ['required', 'string', 'max:150'],
            'iban' => ['required', 'string', 'max:42', new ValidIban],
            'bic' => ['nullable', 'string', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
