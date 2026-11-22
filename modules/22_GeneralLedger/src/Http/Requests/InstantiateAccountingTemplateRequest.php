<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Enums\LedgerBook;

/**
 * Posts a template to the prima nota: the inputs of the preview plus the posting date and the books to propagate to.
 */
final class InstantiateAccountingTemplateRequest extends FormRequest
{
    use TenantScopedRules;

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
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['string', 'max:60'],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantOwns('cost_centers')],
            'posting_date' => ['required', 'date'],
            'fiscal_period_id' => ['nullable', 'uuid', $this->tenantExists('fiscal_periods')],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'in:draft,posted'],
            'post_to_ledgers' => ['nullable', 'array', 'max:3'],
            'post_to_ledgers.*' => ['string', Rule::in(LedgerBook::accepted())],
        ];
    }
}
