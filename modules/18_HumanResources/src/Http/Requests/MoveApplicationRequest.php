<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MoveApplicationRequest extends FormRequest
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

        return ['pipeline_stage_id' => ['required', 'uuid', Rule::exists('hr_pipeline_stages', 'id')->where(static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))]];
    }
}
