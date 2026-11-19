<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Pricing\Http\Requests\ResolvePricingRequest;
use Modules\Pricing\Services\PriceResolutionEngine;

/**
 * Real-time price simulation: resolved unit prices, discounts, promotions, rounding, margin and the rule trace.
 */
final class PricingResolveController extends Controller
{
    public function __construct(private readonly PriceResolutionEngine $engine) {}

    public function resolve(ResolvePricingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $customer = isset($data['customer_id']) ? Contact::query()->findOrFail($data['customer_id']) : null;

        $result = $this->engine->resolve(
            $customer,
            strtoupper((string) $data['currency']),
            (string) ($data['channel'] ?? 'DEFAULT'),
            isset($data['date']) ? CarbonImmutable::parse($data['date']) : CarbonImmutable::today(),
            $data['lines'],
        );

        return response()->json(['data' => $result]);
    }
}
