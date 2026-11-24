<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The time a campaign starts; it must lie in the future.
 */
final class ScheduleCampaignRequest extends FormRequest
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
        return ['scheduled_at' => ['required', 'date', 'after:now']];
    }
}
