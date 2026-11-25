<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Options of the conversion of an awarded tender into a contract and a project.
 */
final class ConvertAwardRequest extends FormRequest
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
        $tenantId = (string) (app(TenantContext::class)->getTenantId() ?? '');

        return [
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'project_manager_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')],
            'planned_start_date' => ['nullable', 'date'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'contract_title' => ['nullable', 'string', 'max:240'],
        ];
    }
}
