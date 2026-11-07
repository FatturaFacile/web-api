<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\ReceiptKind;
use Modules\Associations\Support\TenantRules;

final class UpdateSettingsRequest extends FormRequest
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
            'card_prefix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'card_padding' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'receipt_prefix' => ['sometimes', 'string', 'max:20'],
            'default_receipt_kind' => ['sometimes', Rule::enum(ReceiptKind::class)],
            'fee_reminder_days' => ['sometimes', 'array', 'max:10'],
            'fee_reminder_days.*' => ['integer', 'min:0', 'max:365'],
            'renewal_reminder_days' => ['sometimes', 'array', 'max:10'],
            'renewal_reminder_days.*' => ['integer', 'min:0', 'max:365'],
            'certificate_alert_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'presence_max_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'ledger_enabled' => ['sometimes', 'boolean'],
            'default_revenue_account_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('chart_of_accounts')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key === null && array_key_exists('card_prefix', $validated)) {
            $validated['card_prefix'] = (string) $validated['card_prefix'];
        }

        return $validated;
    }
}
