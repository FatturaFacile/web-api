<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use Modules\Pricing\Http\Requests\SpecialPriceRequest;
use Modules\Pricing\Models\CustomerSpecialPrice;

final class SpecialPriceController extends PricingCrudController
{
    protected function modelClass(): string
    {
        return CustomerSpecialPrice::class;
    }

    protected function requestClass(): string
    {
        return SpecialPriceRequest::class;
    }

    /**
     * @return list<string>
     */
    protected function filters(): array
    {
        return ['customer_id', 'product_id', 'currency'];
    }
}
