<?php

declare(strict_types=1);

namespace App\Http\Requests\Lookups;

use App\Http\Requests\Concerns\TargetsGlobalOrActiveTenant;
use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

class SaveLookupTranslationRequest extends FormRequest
{
    use TargetsGlobalOrActiveTenant, TenantScopedRules;

    /**
     * A label always belongs to the active tenant; only a platform administrator writes the global labels.
     */
    public function authorize(): bool
    {
        return $this->mayTargetRequestedScope();
    }

    /**
     * The clients of this endpoint send tenant_id null to mean the active tenant.
     */
    protected function nullTenantMeansGlobal(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lookup_item_id' => ['required', 'uuid', $this->tenantExists('lookup_items')],
            'locale' => ['required', 'string', 'max:5'],
            'label' => ['required', 'string', 'max:255'],
            'short_label' => ['nullable', 'string', 'max:15'],
            'description' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'string'],
        ];
    }
}
