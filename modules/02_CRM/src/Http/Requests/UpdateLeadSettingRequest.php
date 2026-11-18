<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\CRM\Models\LeadSetting;

final class UpdateLeadSettingRequest extends FormRequest
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
            'warm_threshold' => ['sometimes', 'integer', 'between:0,100'],
            'hot_threshold' => ['sometimes', 'integer', 'between:0,100'],
            'auto_qualify_threshold' => ['sometimes', 'integer', 'between:0,101'],
            'dedup_mode' => ['sometimes', 'string', Rule::in(LeadSetting::DEDUP_MODES)],
            'dedup_levenshtein_max' => ['sometimes', 'integer', 'between:0,10'],
            'dedup_similarity_min' => ['sometimes', 'numeric', 'between:0.5,1'],
            'default_country' => ['sometimes', 'string', 'size:2', 'alpha'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $tenantId = (string) app(TenantContext::class)->getTenantId();
                $current = LeadSetting::forTenant($tenantId);
                $warm = (int) $this->input('warm_threshold', $current->warm_threshold);
                $hot = (int) $this->input('hot_threshold', $current->hot_threshold);

                if ($warm >= $hot) {
                    $validator->errors()->add('warm_threshold', 'The warm threshold must be lower than the hot threshold.');
                }
            },
        ];
    }
}
