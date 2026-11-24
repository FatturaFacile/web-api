<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A vote. The staff names the participant voting in person; in the member area the voter is the person of the token.
 */
final class CastVoteRequest extends FormRequest
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
            'choice' => ['required', 'string', 'max:100'],
            'on_behalf_of' => ['sometimes', 'nullable', 'uuid'],
            'participant_id' => ['sometimes', 'uuid'],
        ];
    }
}
