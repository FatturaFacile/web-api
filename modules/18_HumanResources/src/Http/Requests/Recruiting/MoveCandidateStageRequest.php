<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RejectionReasonCode;

final class MoveCandidateStageRequest extends FormRequest
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
            'stage' => ['required', 'string', Rule::in(ApplicationStage::values())],
            'board_position' => ['nullable', 'integer', 'min:0'],
            'rejection_reason_code' => ['nullable', 'required_if:stage,rejected', 'string', Rule::in(RejectionReasonCode::values())],
            'rejection_note' => ['nullable', 'string', 'max:2000'],
            'send_email' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
