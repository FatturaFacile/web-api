<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReviseBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_revision' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
