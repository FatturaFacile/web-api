<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the completion of a work order with its notes and the customer sign-off.
 */
final class CompleteWorkOrderRequest extends FormRequest
{
    /**
     * Access is decided by the module.access:service middleware.
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
            'completion_notes' => ['nullable', 'string', 'max:5000'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'],
            'customer_signature_url' => ['nullable', 'string', 'max:1000000'],
            'signed_by_customer_name' => ['nullable', 'string', 'max:150'],
            'signed_at' => ['nullable', 'date'],
        ];
    }
}
