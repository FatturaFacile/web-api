<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a depreciation schedule preview.
 */
final class DepreciationPreviewRequest extends FormRequest
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
            'ledger_type' => ['nullable', 'string', 'in:statutory,tax,ifrs,management,civil,fiscal'],
            'period_type' => ['nullable', 'string', 'in:monthly,quarterly,yearly'],
            'periods' => ['nullable', 'integer', 'min:1', 'max:600'],
            'units_per_period' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
