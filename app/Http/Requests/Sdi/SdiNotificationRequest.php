<?php

declare(strict_types=1);

namespace App\Http\Requests\Sdi;

use Illuminate\Foundation\Http\FormRequest;

class SdiNotificationRequest extends FormRequest
{
    /**
     * Webhooks are unauthenticated users, so they must present the shared secret.
     */
    public function authorize(): bool
    {
        $expected = (string) config('services.sdi.webhook_secret');
        $given = (string) $this->header('X-Webhook-Secret');

        return $expected !== '' && hash_equals($expected, $given);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:30'],
            'uuid' => ['required', 'string', 'max:64'],
            'event_type' => ['required', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function failedAuthorization(): void
    {
        abort(401, __('sdi.invalid_webhook_secret'));
    }
}
