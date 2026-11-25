<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordPostDeliverySurveyRequest extends FormRequest
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
            'ddt_invoice_id' => ['nullable', 'uuid'],
            'survey_code' => ['nullable', 'string', 'max:40'],
            'trigger_event' => ['nullable', 'string', 'in:post_delivery_ddt,post_support_ticket,post_onboarding'],
            'nps_score' => ['required', 'integer', 'min:0', 'max:10'],
            'csat_score' => ['required', 'integer', 'min:1', 'max:5'],
            'ces_score' => ['nullable', 'integer', 'min:1', 'max:7'],
            'recovery_action_owner' => ['nullable', 'string', 'max:120'],
            'iso9001_clause_ref' => ['nullable', 'string', 'max:40'],
            'verbatim_feedback' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
