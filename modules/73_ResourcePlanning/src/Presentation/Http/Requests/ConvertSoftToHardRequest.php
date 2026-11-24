<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Commits a tentative booking: the project (and WBS node) it will run on.
 */
final class ConvertSoftToHardRequest extends FormRequest
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

        return [
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('tenant_id', $tenantId)],
            'wbs_node_id' => ['nullable', 'uuid'],
        ];
    }
}
