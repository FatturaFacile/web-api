<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\Country;
use Modules\Beekeeping\Domain\Enums\ProductKind;
use Modules\Beekeeping\Domain\Enums\SaleChannel;
use Modules\Beekeeping\Models\TaxProfile;

/**
 * A VAT quote saves nothing: it needs read access only, even though it is a POST.
 */
final class QuoteTaxRequest extends BeekeepingRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', TaxProfile::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['required', Rule::enum(Country::class)],
            'product_kind' => ['required', Rule::enum(ProductKind::class)],
            'sale_channel' => ['sometimes', Rule::enum(SaleChannel::class)],
            'net_amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
