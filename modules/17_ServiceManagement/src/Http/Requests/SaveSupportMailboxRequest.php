<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or changes a support mailbox. On a change every field is optional and a missing password keeps the stored one.
 */
final class SaveSupportMailboxRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $mailboxId = $this->route('id');

        return [
            'name' => [$required, 'string', 'max:150'],
            'email_address' => [$required, 'email', 'max:180', Rule::unique('esm_support_mailboxes', 'email_address')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($mailboxId)],
            'imap_host' => [$required, 'string', 'max:180'],
            'imap_port' => ['sometimes', 'integer', 'between:1,65535'],
            'imap_username' => [$required, 'string', 'max:255'],
            'imap_password' => [$required, 'string', 'max:255'],
            'default_team_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
            'default_category_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('esm_service_categories')],
            'default_priority' => ['sometimes', 'string', 'in:low,medium,high,critical'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
