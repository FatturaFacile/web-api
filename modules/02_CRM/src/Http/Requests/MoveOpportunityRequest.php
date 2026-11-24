<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Drag-and-drop of a deal on the Kanban board: target column, position inside it and, when the column is a lost
 * stage, the mandatory loss questionnaire (enforced by the service against the stage and the loss-reason catalog).
 */
final class MoveOpportunityRequest extends FormRequest
{
    use TenantScopedRules;

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
            'stage_id' => ['required', 'uuid', $this->tenantOwns('crm_opportunity_stages')],
            'position' => ['nullable', 'integer', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'probability' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'forecast_category' => ['nullable', 'string', 'in:pipeline,best_case,commit,closed,omitted'],
            'actual_close_date' => ['nullable', 'date'],
            'loss_reason_code' => ['nullable', 'string', 'max:50'],
            'loss_notes' => ['nullable', 'string', 'max:2000'],
            'competitor' => ['nullable', 'string', 'max:150'],
        ];
    }
}
