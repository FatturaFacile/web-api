<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DecideTenantAccessRequest extends FormRequest
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
        return [
            'role' => ['nullable', 'string', 'in:owner,admin,accountant,accountant_readonly,technician,member'],
            'tenant_id' => [$this->routeIs('admin.tenant-access-requests.move') ? 'required' : 'prohibited', 'uuid', 'exists:tenants,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
