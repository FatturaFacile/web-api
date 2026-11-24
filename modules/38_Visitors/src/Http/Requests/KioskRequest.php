<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registration of a reception kiosk.
 */
final class KioskRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
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
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
