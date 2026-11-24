<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Contracts\Models\ContractParty;

/**
 * Validates a party linked to a contract. A linked party must belong to the active company (a user must be a member of
 * it); an external party is just a name (access is decided by module.access).
 */
final class SaveContractPartyRequest extends FormRequest
{
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
        $type = (string) $this->input('party_type');
        $isExternal = $type === 'external';

        return [
            'party_type' => ['required', Rule::in(array_keys(ContractParty::Types))],
            'party_id' => [$isExternal ? 'prohibited' : 'required', 'uuid', $this->partyExists($type)],
            'name' => [$isExternal ? 'required' : 'nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(ContractParty::Roles)],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function partyExists(string $type): Exists|string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return match ($type) {
            'contact' => Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            'supplier' => Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            'user' => Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            default => 'nullable',
        };
    }
}
