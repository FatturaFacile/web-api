<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Creates or renames a tag. The name is unique in the company.
 */
final class TagRequest extends CollaborationFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $name = ['string', 'max:60', Rule::unique('collab_tags', 'name')
            ->where('tenant_id', (string) app(TenantContext::class)->getTenantId())
            ->whereNull('deleted_at')
            ->ignore($this->route('id'))];

        return [
            'name' => $this->isMethod('PUT') ? ['sometimes', ...$name] : ['required', ...$name],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
