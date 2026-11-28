<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\WithholdingRecipients\Domain\Enums\Jurisdiction;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

final class RegisterIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', RecipientSettlement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_profile_id' => ['nullable', 'uuid', Rule::exists('fiscal_recipient_profiles', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'regime' => ['nullable', Rule::enum(RecipientRegime::class)],
            'jurisdiction' => ['nullable', Rule::enum(Jurisdiction::class)],
            'tax_year' => ['nullable', 'integer', 'between:2000,2100'],
            'accrual_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
