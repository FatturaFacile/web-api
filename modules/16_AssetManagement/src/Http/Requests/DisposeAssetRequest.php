<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a total or partial disposal (sale, scrapping, retirement, donation, loss).
 */
final class DisposeAssetRequest extends FormRequest
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
            'disposal_type' => ['required', 'string', 'in:sale,scrap,retirement,donation,theft_loss'],
            'disposed_percentage' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:100'],
            'proceeds_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'disposal_costs' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'disposal_date' => ['nullable', 'date'],
            'buyer_contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'reason' => ['nullable', 'string', 'max:2000'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
