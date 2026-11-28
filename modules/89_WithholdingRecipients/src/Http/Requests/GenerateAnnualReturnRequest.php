<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\WithholdingRecipients\Models\CuAutonomousRecord;

final class GenerateAnnualReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settle', CuAutonomousRecord::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax_year' => ['required', 'integer', 'between:2000,2100'],
            'recipient_profile_id' => ['nullable', 'uuid', Rule::exists('fiscal_recipient_profiles', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
        ];
    }
}
