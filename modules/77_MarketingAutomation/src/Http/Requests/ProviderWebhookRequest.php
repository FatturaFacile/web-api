<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Provider callbacks (bounces, complaints) are authenticated by a shared secret, not by a user.
 */
final class ProviderWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $secret = (string) config('marketing_automation.webhook_secret', '');
        $given = (string) ($this->header('X-Webhook-Secret') ?? $this->query('secret', ''));

        return $secret !== '' && hash_equals($secret, $given);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
