<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FiscalRetail\Models\RetailReturn;

/**
 * The refund of everything that is left to return of a POS sale, settled in an open session.
 */
final class RetailSaleRefundRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'pos_session_id' => ['required', 'uuid', Rule::exists('pos_sessions', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'refund_method' => ['required', Rule::in(RetailReturn::RefundMethods)],
            'reason' => ['nullable', 'string', 'max:190'],
        ];
    }
}
