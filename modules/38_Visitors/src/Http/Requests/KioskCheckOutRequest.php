<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Check-out at the kiosk by the badge the visitor gives back.
 */
final class KioskCheckOutRequest extends FormRequest
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
            'badge_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
