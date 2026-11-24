<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Invitation of a supplier of the procurement module to the portal.
 */
final class InviteSupplierRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware.
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
            'supplier_id' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'invitation_email' => ['nullable', 'email', 'max:255'],
            'country' => ['required', 'string', 'size:2', 'in:IT,CH'],
        ];
    }
}
