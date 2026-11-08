<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Application of an issued credit note to an issued invoice of the same customer of the active company.
 */
final class ApplyCreditNoteRequest extends FormRequest
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
            'target_invoice_id' => ['required', 'uuid', $this->tenantOwns('invoices')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }

    /**
     * The amount as a decimal string, so it never goes through a float.
     */
    public function amount(): string
    {
        return trim((string) $this->validated('amount'));
    }
}
