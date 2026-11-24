<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigns a conversation to a member of the company, or unassigns it with a null user.
 */
final class AssignConversationRequest extends FormRequest
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
            'user_id' => [
                'present',
                'nullable',
                'uuid',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId()),
            ],
        ];
    }
}
