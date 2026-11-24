<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskInsightsService;

/**
 * Query of the breach risk queue (risk_percent, limit) and of the CSAT and MTTR metrics (from, to, group_by).
 */
final class HelpdeskInsightsRequest extends FormRequest
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
            'risk_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['sometimes', 'string', Rule::in(HelpdeskInsightsService::GroupBy)],
        ];
    }
}
