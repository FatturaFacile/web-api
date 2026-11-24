<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Notes of a review step: a reason is required to reject or block a supplier.
 */
final class OnboardingActionRequest extends FormRequest
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
        $needsReason = $this->routeIs('*.reject', '*.block');

        return [
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
