<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A tenant template (causale contabile); also used to update one, where only the sent fields are validated.
 */
final class StoreAccountingTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId() ?? $this->user()?->tenant_id;
        $unique = Rule::unique('accounting_templates', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        if ($this->route('id') !== null) {
            $unique = $unique->ignore((string) $this->route('id'));
        }

        return [
            'code' => [$required, 'string', 'max:50', 'regex:/^[A-Z0-9_\-]+$/', $unique],
            'category' => ['nullable', 'string', 'in:general,payroll,interest,self_invoice,advance,expense,revenue,vat,tax'],
            'journal_type' => ['nullable', 'string', 'in:standard,adjusting'],
            'ledger_type' => ['nullable', 'string', 'in:statutory,tax,ifrs'],
            'names' => [$required, 'array'],
            'names.it' => ['required_with:names', 'string', 'max:255'],
            'names.de' => ['required_with:names', 'string', 'max:255'],
            'names.fr' => ['required_with:names', 'string', 'max:255'],
            'names.en' => ['required_with:names', 'string', 'max:255'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.*' => ['nullable', 'string', 'max:500'],
            'parameters' => [$required, 'array'],
            'parameters.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'parameters.*.type' => ['required', 'string', 'in:account,amount,rate'],
            'lines' => [$required, 'array', 'min:2'],
            'lines.*.account' => ['required', 'string', 'max:60'],
            'lines.*.side' => ['required', 'string', 'in:debit,credit'],
            'lines.*.amount' => ['required', 'string', 'in:amount,tax,gross'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
