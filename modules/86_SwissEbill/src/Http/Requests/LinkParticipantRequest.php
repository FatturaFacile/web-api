<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LinkParticipantRequest extends FormRequest
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
            'participant_id' => ['required', 'string', 'max:64'],
        ];
    }
}
