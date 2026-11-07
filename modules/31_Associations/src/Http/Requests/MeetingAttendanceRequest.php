<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\AttendanceStatus;

final class MeetingAttendanceRequest extends FormRequest
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
            'entries' => ['required', 'array', 'min:1', 'max:5000'],
            'entries.*.participant_id' => ['required', 'uuid'],
            'entries.*.attendance' => ['required', Rule::enum(AttendanceStatus::class)],
        ];
    }
}
