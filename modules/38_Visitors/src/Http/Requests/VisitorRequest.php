<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Personal data of a visitor, on creation (POST) or change (PUT).
 */
final class VisitorRequest extends FormRequest
{
    use TenantScopedRules;

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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'first_name' => [$presence, 'string', 'max:100'],
            'last_name' => [$presence, 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'document_number' => ['nullable', 'string', 'max:50'],
        ];
    }
}
