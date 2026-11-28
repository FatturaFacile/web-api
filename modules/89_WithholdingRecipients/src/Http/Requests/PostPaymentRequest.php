<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

final class PostPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settle', RecipientSettlement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_profile_id' => ['required', 'uuid', Rule::exists('fiscal_recipient_profiles', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            ...self::paymentRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentRules(string $prefix = ''): array
    {
        return [
            $prefix.'gross_amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            $prefix.'payment_date' => ['required', 'date'],
            $prefix.'service_date' => ['nullable', 'date'],
            $prefix.'document_number' => ['nullable', 'string', 'max:60'],
            $prefix.'payment_reference' => ['nullable', 'string', 'max:120'],
            $prefix.'performance_days' => ['nullable', 'integer', 'between:1,366'],
            $prefix.'acquisition_expenses' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            $prefix.'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
