<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activation of a draft lease: measures it and, by default, posts the recognition journal.
 */
final class ActivateLeaseRequest extends FormRequest
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
            'post_recognition' => ['sometimes', 'boolean'],
        ];
    }
}
