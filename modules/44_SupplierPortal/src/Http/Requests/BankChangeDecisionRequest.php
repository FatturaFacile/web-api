<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A step on a bank detail change request: the callback note, an approval comment, or the reason of a rejection.
 */
final class BankChangeDecisionRequest extends FormRequest
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
            'note' => [$this->routeIs('*.verify-callback') ? 'required' : 'nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'reason' => [$this->routeIs('*.reject') ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }
}
