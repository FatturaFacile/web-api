<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Semantic\SemanticCatalog;

final class SaveDesignedReportRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('analytics_designed_reports', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $this->user()?->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('id')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'dataset' => ['required', 'string', Rule::in(array_keys(app(SemanticCatalog::class)->all()))],
            'spec' => ['required', 'array'],
            'pivot' => ['nullable', 'array'],
            'calculated_fields' => ['nullable', 'array', 'max:10'],
            'conditional_formatting' => ['nullable', 'array', 'max:20'],
            'chart_config' => ['nullable', 'array'],
            'is_shared' => ['nullable', 'boolean'],
            'visibility' => ['nullable', Rule::in(DesignedReport::Visibilities)],
            'shared_role_ids' => ['nullable', 'array', 'max:20'],
            'shared_role_ids.*' => ['string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', 'distinct'],
            'shared_with' => ['nullable', 'array', 'max:50'],
            'shared_with.*' => ['uuid', 'distinct', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId)],
        ];
    }

    /**
     * The visibility asked for; the older `is_shared` flag still means "the whole company".
     */
    public function visibility(): string
    {
        return (string) ($this->input('visibility') ?? ($this->boolean('is_shared') ? DesignedReport::VisibilityTenantPublic : DesignedReport::VisibilityPrivate));
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dataset' => (string) $this->input('dataset'),
            'spec' => (array) $this->input('spec'),
            'pivot' => (array) $this->input('pivot', []),
            'calculated_fields' => (array) $this->input('calculated_fields', []),
            'conditional_formatting' => (array) $this->input('conditional_formatting', []),
            'chart_config' => (array) $this->input('chart_config', []),
        ];
    }
}
