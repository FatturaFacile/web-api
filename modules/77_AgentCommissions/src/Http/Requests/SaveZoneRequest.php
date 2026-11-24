<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AgentCommissions\Models\Zone;

final class SaveZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Zone::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $creating = $this->isMethod('POST');
        $unique = Rule::unique('agc_zones', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        if (! $creating) {
            $unique->ignore((string) $this->route('id'));
        }

        return [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:40', $unique],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'country' => ['nullable', 'string', 'size:2'],
            'regions' => ['nullable', 'array', 'max:200'],
            'regions.*' => ['string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
