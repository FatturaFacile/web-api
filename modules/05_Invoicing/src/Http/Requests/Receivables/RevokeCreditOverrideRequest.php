<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Revocation of a credit override with an optional reason.
 */
final class RevokeCreditOverrideRequest extends FormRequest
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
        return ['reason' => ['nullable', 'string', 'max:2000']];
    }
}
