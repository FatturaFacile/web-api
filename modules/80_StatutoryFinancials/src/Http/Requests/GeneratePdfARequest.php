<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GeneratePdfARequest extends FormRequest
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
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => ['string', Rule::in(['management_report', 'board_minutes', 'shareholders_minutes', 'dossier'])],
            'meeting' => ['sometimes', 'array'],
            'meeting.meeting_date' => ['sometimes', 'string', 'max:40'],
            'meeting.meeting_place' => ['sometimes', 'nullable', 'string', 'max:160'],
            'meeting.chair' => ['sometimes', 'nullable', 'string', 'max:160'],
            'meeting.secretary' => ['sometimes', 'nullable', 'string', 'max:160'],
            'meeting.attendees' => ['sometimes', 'array', 'max:60'],
            'meeting.attendees.*' => ['string', 'max:160'],
        ];
    }
}
