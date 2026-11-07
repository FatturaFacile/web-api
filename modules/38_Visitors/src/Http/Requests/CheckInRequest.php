<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The badge handed to the visitor at check-in.
 */
final class CheckInRequest extends FormRequest
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
            'badge_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
