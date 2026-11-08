<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An expected visit: a known visitor or the data of a new one, the host and the expected time.
 */
final class VisitRequest extends FormRequest
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
        return [
            'visitor_id' => ['required_without:visitor', 'nullable', 'uuid', $this->tenantOwns('visitors')],
            'visitor' => ['required_without:visitor_id', 'nullable', 'array'],
            'visitor.contact_id' => ['nullable', 'uuid', $this->tenantExists('contacts')],
            'visitor.first_name' => ['required_with:visitor', 'string', 'max:100'],
            'visitor.last_name' => ['required_with:visitor', 'string', 'max:100'],
            'visitor.company_name' => ['nullable', 'string', 'max:150'],
            'visitor.email' => ['nullable', 'email', 'max:255'],
            'visitor.phone' => ['nullable', 'string', 'max:40'],
            'host_user_id' => ['required', 'uuid', $this->tenantMember()],
            'expected_at' => ['required', 'date'],
            'expected_until' => ['nullable', 'date', 'after:expected_at'],
            'purpose' => ['nullable', 'string', 'max:500'],
        ];
    }
}
