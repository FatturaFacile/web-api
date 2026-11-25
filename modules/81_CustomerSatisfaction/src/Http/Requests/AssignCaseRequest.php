<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\CustomerSatisfaction\Http\Requests\Concerns\ScopesToTenant;

final class AssignCaseRequest extends FormRequest
{
    use ScopesToTenant;

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
            'assigned_account_manager_id' => ['nullable', 'uuid', $this->tenantMember()],
            'assigned_support_lead_id' => ['nullable', 'uuid', $this->tenantMember()],
        ];
    }
}
