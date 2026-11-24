<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A failed collection (returned direct debit, declined card) of an invoice of the subscription.
 */
final class CollectionFailureRequest extends FormRequest
{
    use TenantRules;

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
            'invoice_id' => ['required', 'uuid', $this->existsInTenant('invoices')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
