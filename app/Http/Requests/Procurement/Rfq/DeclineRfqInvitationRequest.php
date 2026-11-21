<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates optional decline reason submitted by an external supplier.
 */
final class DeclineRfqInvitationRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
