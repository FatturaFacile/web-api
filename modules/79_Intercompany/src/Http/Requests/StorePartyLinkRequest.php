<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StorePartyLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_tenant_id' => ['required', 'uuid'],
            'counterparty_tenant_id' => ['required', 'uuid', 'different:entity_tenant_id'],
            'contact_id' => ['required', 'uuid'],
            'role' => ['required', Rule::in(['customer', 'supplier'])],
        ];
    }
}
