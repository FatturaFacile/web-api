<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of the asset register query.
 */
final class AssetRegisterRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policies of the controller.
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
            'year' => ['required', 'integer', 'min:1900', 'max:2200'],
            'ledger_type' => ['nullable', 'string', 'in:statutory,tax,ifrs,management,civil,fiscal'],
            'format' => ['nullable', 'string', 'in:json,csv'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
