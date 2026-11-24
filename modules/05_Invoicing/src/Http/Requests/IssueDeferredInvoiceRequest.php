<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoicing\Http\Requests\Concerns\BillingOverrideRules;

/**
 * A deferred invoice for dispatched delivery notes of the same customer, all of the active company.
 */
final class IssueDeferredInvoiceRequest extends FormRequest
{
    use BillingOverrideRules, TenantScopedRules;

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
            'delivery_note_ids' => ['required', 'array', 'min:1', 'max:200'],
            'delivery_note_ids.*' => ['required', 'uuid', 'distinct', $this->tenantOwns('delivery_notes')],
            ...$this->overrideRules(),
        ];
    }
}
