<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ToolBoxTalkRequest extends FormRequest
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
            'talk_id' => ['nullable', 'uuid'],
            'topic' => ['required', 'string', 'max:200'],
            'site_or_work_center' => ['nullable', 'string', 'max:160'],
            'checklist_points' => ['nullable', 'array'],
            'checklist_points.*' => ['string', 'max:300'],
            'supervisor_employee_id' => ['nullable', 'uuid'],
            'conducted_at' => ['required', 'date'],
            'attendances' => ['required', 'array', 'min:1', 'max:500'],
            'attendances.*.employee_id' => ['required', 'uuid'],
            'attendances.*.signature_payload' => ['required', 'string', 'max:200000'],
            'attendances.*.signature_image' => ['nullable', 'string', 'max:2000000'],
            'attendances.*.signed_at' => ['required', 'date'],
        ];
    }
}
