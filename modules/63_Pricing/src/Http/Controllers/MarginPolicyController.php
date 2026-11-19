<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use Modules\Pricing\Http\Requests\MarginPolicyRequest;
use Modules\Pricing\Models\MarginPolicy;

final class MarginPolicyController extends PricingCrudController
{
    protected function modelClass(): string
    {
        return MarginPolicy::class;
    }

    protected function requestClass(): string
    {
        return MarginPolicyRequest::class;
    }

    /**
     * @return list<string>
     */
    protected function filters(): array
    {
        return ['scope', 'scope_id'];
    }
}
