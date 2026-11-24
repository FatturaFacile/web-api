<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a direct capital improvement or extraordinary capitalized maintenance on an asset.
 */
final class RecordAssetImprovementRequest extends FormRequest
{
    use TenantScopedRules;

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
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'description' => ['required', 'string', 'max:500'],
            'improvement_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'purchase_invoice_id' => ['nullable', 'uuid', $this->tenantOwns('passive_invoices')],
            'purchase_invoice_number' => ['nullable', 'string', 'max:100'],
            'extends_useful_life_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'affects_tax_book' => ['nullable', 'boolean'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
