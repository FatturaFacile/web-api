<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A pause (or suspension) with its reason and, for a pause, the optional date it ends by itself.
 */
final class PauseSubscriptionRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:255'],
            'resume_on' => ['nullable', 'date', 'after:today'],
        ];
    }
}
