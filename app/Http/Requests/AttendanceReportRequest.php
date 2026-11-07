<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceReportRequest extends FormRequest
{
    /**
     * Everybody reads their own report; the report of a colleague is for whoever reads the HR module of the tenant.
     */
    public function authorize(ModuleAccessGate $gate, TenantContext $tenantContext): bool
    {
        /** @var User $actor */
        $actor = $this->user();
        $targetUserId = $this->input('user_id');

        if ($targetUserId === null || (string) $targetUserId === (string) $actor->getKey()) {
            return true;
        }

        return $gate->allows($actor, $tenantContext->getTenantId(), 'hr', 'read');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'user_id' => ['nullable', 'uuid'],
            'jurisdiction' => ['nullable', 'in:IT,CH'],
        ];
    }
}
