<?php

declare(strict_types=1);

namespace App\Http\Requests\Sdi;

/**
 * Incoming-invoice webhook: same shared-secret protection as the SDI notification webhook.
 */
class ReceiveIncomingInvoiceRequest extends SdiNotificationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['nullable', 'string', 'max:30'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'xml' => ['required', 'string', 'max:10485760'],
        ];
    }
}
