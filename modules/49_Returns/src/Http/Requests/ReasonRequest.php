<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A free-text reason for a rejection or a voiding.
 */
final class ReasonRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
