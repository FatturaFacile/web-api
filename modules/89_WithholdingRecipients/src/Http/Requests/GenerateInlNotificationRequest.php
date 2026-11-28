<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;

final class GenerateInlNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', OccasionalInlNotification::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_start_date' => ['required', 'date'],
            'work_end_date' => ['required', 'date', 'after_or_equal:work_start_date'],
            'activity_description' => ['required', 'string', 'max:2000'],
            'work_place' => ['nullable', 'string', 'max:200'],
            'estimated_compensation' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
