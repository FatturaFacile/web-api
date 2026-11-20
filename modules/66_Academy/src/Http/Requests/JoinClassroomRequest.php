<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class JoinClassroomRequest extends FormRequest
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
        return ['join_code' => ['required', 'string', 'min:4', 'max:12']];
    }
}
