<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Communication\Models\CommunicationCampaign;
use Modules\Communication\Models\ContactChannelPreference;

/**
 * A campaign: channel, purpose, the CRM segment of the audience and the message. A PUT only validates what it sends.
 */
final class CampaignRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::in(ContactChannelPreference::Channels)],
            'purpose' => ['sometimes', Rule::in(CommunicationCampaign::Purposes)],
            'segment_id' => [
                'nullable',
                'uuid',
                Rule::exists('crm_segments', 'id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at'),
            ],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
        ];

        return $this->isMethod('PUT') ? array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules) : $rules;
    }
}
