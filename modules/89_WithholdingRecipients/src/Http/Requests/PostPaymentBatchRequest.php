<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

final class PostPaymentBatchRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.recipient_profile_id' => ['required', 'uuid', Rule::exists('fiscal_recipient_profiles', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            ...PostPaymentRequest::paymentRules('items.*.'),
        ];
    }
}
