<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User || app(TenantContext::class)->getTenantId() === null) {
            return false;
        }

        return ! $this->filled('user_id')
            || $this->input('user_id') === $user->id
            || $user->can('view-all-attendance');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'user_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', app(TenantContext::class)->getTenantId()),
            ],
            'jurisdiction' => ['nullable', 'in:IT,CH'],
        ];
    }
}
