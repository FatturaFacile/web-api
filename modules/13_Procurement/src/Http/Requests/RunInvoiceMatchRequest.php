<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starts the matching of one supplier invoice, optionally against a named purchase order.
 */
final class RunInvoiceMatchRequest extends FormRequest
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
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        return [
            'passive_invoice_id' => ['required', 'uuid', Rule::exists('passive_invoices', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'purchase_order_id' => ['nullable', 'uuid', Rule::exists('purchase_orders', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ];
    }
}
