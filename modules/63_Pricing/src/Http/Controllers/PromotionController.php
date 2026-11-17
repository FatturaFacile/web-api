<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use Modules\Pricing\Http\Requests\PromotionRequest;
use Modules\Pricing\Models\Promotion;

final class PromotionController extends PricingCrudController
{
    protected function modelClass(): string
    {
        return Promotion::class;
    }

    protected function requestClass(): string
    {
        return PromotionRequest::class;
    }

    /**
     * @return list<string>
     */
    protected function filters(): array
    {
        return ['type', 'is_active'];
    }
}
