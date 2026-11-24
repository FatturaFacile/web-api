<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\DeliveryChannel;
use Modules\Associations\Enums\DeliveryMode;
use Modules\Associations\Support\TenantRules;

/**
 * Sends a notice or minutes on a messaging channel, to everybody, to those not reached yet or to a selection.
 */
final class SendDocumentRequest extends FormRequest
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
            'channel' => ['required', Rule::enum(DeliveryChannel::class)->except([DeliveryChannel::Letter])],
            'mode' => ['required', Rule::enum(DeliveryMode::class)],
            'member_ids' => ['required_if:mode,selected', 'array', 'max:5000'],
            'member_ids.*' => ['uuid', TenantRules::exists('assoc_members')],
        ];
    }
}
