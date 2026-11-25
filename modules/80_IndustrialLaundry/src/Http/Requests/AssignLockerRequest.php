<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Assigns a garment to an employee and a locker; sending no employee releases it.
 */
final class AssignLockerRequest extends FormRequest
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
            'employee_name' => ['nullable', 'string', 'max:160'],
            'locker_code' => ['nullable', 'required_with:employee_name', 'string', 'max:40'],
            'customer_id' => ['nullable', 'uuid'],
        ];
    }
}
