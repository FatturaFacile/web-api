<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Transport data given when a transfer is dispatched.
 */
final class DispatchTransferRequest extends FormRequest
{
    use ScopesToTenant;

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
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'ddt_reference' => ['nullable', 'string', 'max:120'],
            'expected_arrival_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
