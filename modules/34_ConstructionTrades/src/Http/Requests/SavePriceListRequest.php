<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ConstructionTrades\Models\PriceList;

/**
 * Validates a price list of the company.
 */
final class SavePriceListRequest extends FormRequest
{
    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A list from a licensed catalogue records the licence it is used under.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:150'],
            'source' => [$presence, Rule::in(PriceList::Sources)],
            'edition' => ['sometimes', 'nullable', 'string', 'max:40'],
            'license_reference' => ['nullable', 'string', 'max:150', Rule::requiredIf(fn (): bool => in_array($this->input('source'), ['npk', 'crb', 'asip'], true))],
            'currency' => [$presence, 'string', 'size:3'],
            'languages' => [$presence, 'array', 'min:1'],
            'languages.*' => ['string', 'distinct', Rule::in(PriceList::Languages)],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
