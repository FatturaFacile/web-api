<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A construction site (cantiere) referenced by progress billings (SAL) and by bids.
 */
final class SaveConstructionSiteRequest extends FormRequest
{
    use TenantScopedRules;

    /** @var list<string> */
    public const array Statuses = ['planning', 'active', 'paused', 'completed'];

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'client_id' => [$presence, 'uuid', $this->tenantOwns('contacts')],
            'code' => [$presence, 'string', 'max:50', Rule::unique('construction_sites', 'code')->where('tenant_id', $tenantId)->ignore($this->route('id'))],
            'name' => [$presence, 'string', 'max:255'],
            'address' => [$presence, 'string', 'max:255'],
            'sia_norm_reference' => ['sometimes', 'nullable', 'string', 'max:50'],
            'npk_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'estimated_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in(self::Statuses)],
        ];
    }
}
