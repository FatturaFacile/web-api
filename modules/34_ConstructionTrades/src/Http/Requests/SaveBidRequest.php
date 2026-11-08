<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ConstructionTrades\Models\PriceList;

/**
 * Validates a bid: customer and site of the company, title, language, currency and discount.
 */
final class SaveBidRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tax, discount and retention rates are entered by the user for each bid.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'construction_site_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('construction_sites')],
            'contact_id' => [$presence, 'uuid', $this->tenantOwns('contacts')],
            'title' => [$presence, 'string', 'max:200'],
            'language' => [$presence, Rule::in(PriceList::Languages)],
            'currency' => [$presence, 'string', 'size:3'],
            'tax_rate' => [$presence, 'decimal:0,2', 'min:0', 'max:100'],
            'discount_percentage' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'retention_percentage' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'labour_hour_rate' => ['sometimes', 'decimal:0,2', 'min:0'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
