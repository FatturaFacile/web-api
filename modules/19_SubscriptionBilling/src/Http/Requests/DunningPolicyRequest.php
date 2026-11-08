<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The dunning policy of the company: increasing escalation days and the collection retries.
 */
final class DunningPolicyRequest extends FormRequest
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
            'policy_name' => ['required', 'string', 'max:100'],
            'soft_reminder_days' => ['required', 'integer', 'min:0', 'max:365'],
            'formal_notice_days' => ['required', 'integer', 'gt:soft_reminder_days', 'max:365'],
            'suspension_days' => ['required', 'integer', 'gt:formal_notice_days', 'max:730'],
            'termination_days' => ['required', 'integer', 'gt:suspension_days', 'max:1095'],
            'max_collection_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'retry_interval_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
