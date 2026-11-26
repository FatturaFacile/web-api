<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\Security\SecurityAuditCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filters of the security audit viewer. `event_type` takes one type or a comma separated list, each from the catalog.
 */
class ListSecurityAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'subject_user_id' => ['sometimes', 'nullable', 'uuid'],
            'event_type' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'nullable', Rule::in(['success', 'failure'])],
            'auth_method' => ['sometimes', 'nullable', Rule::in(['password', 'sso', 'sso_saml', 'sso_oidc', 'passkey'])],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $given = $this->input('event_type');

                if (! is_string($given) || trim($given) === '') {
                    return;
                }

                $known = SecurityAuditCatalog::types();

                foreach (explode(',', $given) as $type) {
                    if (! in_array(strtoupper(trim($type)), $known, true)) {
                        $validator->errors()->add('event_type', __('advanced_auth.errors.unknown_event_type', ['type' => trim($type)]));
                    }
                }
            },
        ];
    }
}
