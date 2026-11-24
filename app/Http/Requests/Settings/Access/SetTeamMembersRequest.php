<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Access;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The complete list of members of a team.
 */
class SetTeamMembersRequest extends FormRequest
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
            'user_ids' => ['present', 'array', 'max:1000'],
            'user_ids.*' => ['uuid'],
        ];
    }
}
