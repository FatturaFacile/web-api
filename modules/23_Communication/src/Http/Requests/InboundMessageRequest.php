<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Communication\Models\ContactChannelPreference;

/**
 * An inbound message to record in the inbox: the channel, who wrote and what.
 */
final class InboundMessageRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
            'channel' => ['required', Rule::in(ContactChannelPreference::Channels)],
            'address' => ['required', 'string', 'max:190'],
            'name' => ['nullable', 'string', 'max:160'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'received_at' => ['nullable', 'date'],
        ];
    }
}
