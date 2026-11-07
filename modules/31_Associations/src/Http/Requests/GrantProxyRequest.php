<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GrantProxyRequest extends FormRequest
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
            'grantor_participant_id' => ['required', 'uuid'],
            'holder_participant_id' => ['required', 'uuid', 'different:grantor_participant_id'],
        ];
    }
}
