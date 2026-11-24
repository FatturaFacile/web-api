<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOpportunityStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_stage_code' => ['required', 'string', 'max:50'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'probability' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'forecast_category' => ['nullable', 'string', 'in:pipeline,best_case,commit,closed,omitted'],
            'win_loss_reason' => ['nullable', 'string', 'max:255'],
            'competitor' => ['nullable', 'string', 'max:150'],
            'actual_close_date' => ['nullable', 'date'],
        ];
    }
}
