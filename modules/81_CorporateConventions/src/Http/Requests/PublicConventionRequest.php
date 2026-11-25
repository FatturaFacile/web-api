<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\Relationship;

/**
 * Self-service of the employee on the public landing page: request an OTP, verify it, verify a card or matricola, add a family member.
 * The rules depend on the route; the form is public, so every field is bounded.
 */
final class PublicConventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('corporate-conventions.public.request-otp') => ['email' => ['required', 'email:rfc', 'max:190']],
            $this->routeIs('corporate-conventions.public.verify-otp') => [
                'email' => ['required', 'email:rfc', 'max:190'],
                'code' => ['required', 'digits:6'],
                'full_name' => ['required', 'string', 'min:2', 'max:160'],
            ],
            $this->routeIs('corporate-conventions.public.verify-identifier') => [
                'identifier' => ['required', 'string', 'min:3', 'max:120'],
                'full_name' => ['required', 'string', 'min:2', 'max:160'],
                'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            ],
            default => [
                'full_name' => ['required', 'string', 'min:2', 'max:160'],
                'relationship' => ['required', Rule::in([Relationship::Spouse->value, Relationship::Child->value, Relationship::Parent->value])],
            ],
        };
    }
}
