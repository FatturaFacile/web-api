<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\Sso\ProvisionableRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of an update of the tenant security policy. Every group is optional: only what is sent changes.
 */
class UpdateSecurityPolicyRequest extends FormRequest
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
            'password' => ['sometimes', 'array'],
            'password.min_length' => ['sometimes', 'integer', 'min:8', 'max:128'],
            'password.require_mixed_case' => ['sometimes', 'boolean'],
            'password.require_numbers' => ['sometimes', 'boolean'],
            'password.require_symbols' => ['sometimes', 'boolean'],
            'password.history_count' => ['sometimes', 'integer', 'min:0', 'max:24'],
            'password.max_age_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730'],
            'lockout' => ['sometimes', 'array'],
            'lockout.max_attempts' => ['sometimes', 'integer', Rule::in([0, ...range(3, 20)])],
            'lockout.minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'session' => ['sometimes', 'array'],
            'session.idle_timeout_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'session.max_concurrent_sessions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'two_factor' => ['sometimes', 'array'],
            'two_factor.require_for' => ['sometimes', 'string', Rule::in(['none', 'all', 'roles'])],
            'two_factor.roles' => ['sometimes', 'array', 'max:30'],
            'two_factor.roles.*' => ['string', Rule::in(['owner', ...ProvisionableRoles::all()])],
            'sso_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('two_factor.require_for') === 'roles' && $this->input('two_factor.roles', []) === []) {
                    $validator->errors()->add('two_factor.roles', __('enterprise_auth.errors.roles_required'));
                }
            },
        ];
    }
}
