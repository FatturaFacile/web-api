<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;

final class UpdateRecruitingSettingsRequest extends FormRequest
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
        $localeKeys = implode(',', RecruitingLocale::values());

        return [
            'careers' => ['sometimes', 'array'],
            'careers.enabled' => ['sometimes', 'boolean'],
            'careers.slug' => [
                'sometimes', 'nullable', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('rec_settings', 'careers_slug')->where(fn ($query) => $query->where('tenant_id', '!=', $tenantId)->whereNull('deleted_at')),
            ],
            'careers.company_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'careers.intro' => ['sometimes', 'nullable', 'array:'.$localeKeys],
            'careers.intro.*' => ['nullable', 'string', 'max:10000'],
            'careers.privacy_notice' => ['sometimes', 'nullable', 'array:'.$localeKeys],
            'careers.privacy_notice.*' => ['nullable', 'string', 'max:20000'],
            'careers.privacy_policy_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'careers.consent_text_version' => ['sometimes', 'string', 'max:60'],
            'retention' => ['sometimes', 'array'],
            'retention.default_months' => ['sometimes', 'integer', Rule::in(config('recruiting.retention.allowed_months', [6, 12]))],
            'pipeline' => ['sometimes', 'array'],
            'pipeline.stages' => ['sometimes', 'array'],
            'pipeline.stages.*.stage' => ['required', 'string', 'distinct', Rule::in(ApplicationStage::values())],
            'pipeline.stages.*.enabled' => ['sometimes', 'boolean'],
            'pipeline.stages.*.auto_email' => ['sometimes', 'boolean'],
            'pipeline.stages.*.labels' => ['sometimes', 'nullable', 'array:'.$localeKeys],
            'pipeline.stages.*.labels.*' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * `applied`, `hired` and `rejected` cannot be disabled.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('pipeline.stages', []) as $index => $stage) {
                    if (! is_array($stage)) {
                        continue;
                    }

                    $enum = ApplicationStage::tryFrom((string) ($stage['stage'] ?? ''));
                    if ($enum !== null && ! $enum->canBeDisabled() && array_key_exists('enabled', $stage) && ! filter_var($stage['enabled'], FILTER_VALIDATE_BOOLEAN)) {
                        $validator->errors()->add("pipeline.stages.{$index}.enabled", "The stage '{$enum->value}' cannot be disabled.");
                    }
                }
            },
        ];
    }
}
