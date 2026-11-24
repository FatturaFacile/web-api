<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Requests of the family portal. Each route names the payload it validates.
 */
final class FamilyRequest extends FormRequest
{
    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
        return match (str_replace('api.v1.childcare-family.', '', (string) $this->route()?->getName())) {
            'attendance' => ['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']],
            'daily-report' => ['date' => ['required', 'date']],
            'documents.sign' => ['signed_name' => ['required', 'string', 'min:3', 'max:150'], 'accept' => ['accepted']],
            'messages.store' => ['child_id' => ['required', 'uuid'], 'body' => ['required', 'string', 'max:5000']],
            default => [],
        };
    }
}
