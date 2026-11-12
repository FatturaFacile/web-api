<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the give notice operation (authorization is decided by module.access).
 */
final class GiveContractNoticeRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The day the notice was given: today when absent, never in the future.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['notice_given_at' => ['nullable', 'date', 'before_or_equal:today']];
    }
}
