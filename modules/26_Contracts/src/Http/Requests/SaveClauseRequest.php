<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * A clause of the library: a POST also carries the first wording, a PUT only the descriptive fields.
 */
final class SaveClauseRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'code' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        if ($this->isMethod('POST')) {
            $rules['code'][] = Rule::unique('contract_clauses', 'code')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at');
            $rules['body'] = ['required', 'string', 'max:100000'];
            $rules['change_note'] = ['nullable', 'string', 'max:500'];
        }

        return $rules;
    }
}
