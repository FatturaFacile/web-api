<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The value and the source of the next version of an emission factor.
 */
final class NewFactorVersionRequest extends FormRequest
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
            'kg_co2e_per_unit' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'source' => ['required', 'string', 'max:190'],
            'source_year' => ['required', 'integer', 'min:1990', 'max:2100'],
        ];
    }
}
