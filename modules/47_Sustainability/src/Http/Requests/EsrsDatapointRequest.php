<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Models\EsgEsrsDatapoint;

/**
 * A custom ESRS data point of the company catalog (the code is fixed once created).
 */
final class EsrsDatapointRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'standard' => ['required', Rule::in(EsgEsrsDatapoint::Standards)],
            'disclosure' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'data_type' => ['nullable', Rule::in(EsgEsrsDatapoint::DataTypes)],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_mandatory' => ['nullable', 'boolean'],
            'auto_source' => ['nullable', Rule::in(EsgEsrsDatapoint::AutoSources)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($this->isMethod('PUT')) {
            return array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules);
        }

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('esg_esrs_datapoints', 'code')->where('tenant_id', (string) (app(TenantContext::class)->getTenantId() ?? $this->user()?->tenant_id))->whereNull('deleted_at')],
            ...$rules,
        ];
    }
}
