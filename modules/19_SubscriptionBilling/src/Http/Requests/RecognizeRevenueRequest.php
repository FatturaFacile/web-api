<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The date up to which the deferred revenue is recognized (today when omitted).
 */
final class RecognizeRevenueRequest extends FormRequest
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
            'period_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
