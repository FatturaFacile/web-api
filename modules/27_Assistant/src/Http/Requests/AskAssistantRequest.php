<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the input of the ask assistant operation (authorization is decided by module.access). A thread is the
 * caller's own: another user's thread, or one of another tenant, fails validation.
 */
final class AskAssistantRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
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
            'question' => ['required', 'string', 'max:500'],
            'thread_id' => ['nullable', 'uuid', Rule::exists('assistant_threads', 'id')
                ->where('tenant_id', (string) app(TenantContext::class)->getTenantId())
                ->where('user_id', (string) $this->user()?->id)
                ->whereNull('deleted_at')],
        ];
    }
}
