<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\RfmSegmentCode;

final class ExecuteMarketingCampaignRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'campaign_code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:180'],
            'channel' => ['nullable', 'string', 'in:dem_email,sms,whatsapp,omnichannel'],
            'rfm_recency_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rfm_frequency_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rfm_monetary_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rfm_segment' => ['nullable', Rule::in(RfmSegmentCode::values())],
            'journey_definition' => ['nullable', 'array'],
            'exclude_insolvent_overdue' => ['nullable', 'boolean'],
            'gdpr_nlpd_lcsl_consent_verified' => ['nullable', 'boolean'],
            'target_audience_count' => ['nullable', 'integer', 'min:1'],
            'opted_out_suppressed_count' => ['nullable', 'integer', 'min:0'],
            'campaign_cost' => ['nullable', 'numeric', 'min:0'],
            'attributed_revenue' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
