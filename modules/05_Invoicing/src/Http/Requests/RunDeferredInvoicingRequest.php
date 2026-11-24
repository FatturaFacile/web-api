<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The deferred billing run: every dispatched delivery note up to a date, optionally of one customer.
 */
final class RunDeferredInvoicingRequest extends FormRequest
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
            'up_to_date' => ['required', 'date'],
            'contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'date' => ['nullable', 'date', 'after_or_equal:up_to_date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:up_to_date'],
        ];
    }
}
