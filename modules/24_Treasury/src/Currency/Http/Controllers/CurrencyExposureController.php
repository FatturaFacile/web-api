<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Treasury\Currency\Application\Handlers\GetCurrencyExposureHandler;
use Modules\Treasury\Currency\Application\Queries\GetCurrencyExposureQuery;
use Modules\Treasury\Currency\Http\Controllers\Concerns\InteractsWithCurrency;
use Modules\Treasury\Currency\Http\Requests\ExposureRequest;
use OpenApi\Attributes as OA;

final class CurrencyExposureController extends Controller
{
    use ApiResponse;
    use InteractsWithCurrency;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/currencies/exposure', operationId: 'currencyExposure', summary: 'Currency exposure at a day', description: 'Per foreign currency the open receivables, payables, bank and cash balances (in the currency), their historical value, their current value at the rate of as_of (default today) and the unrealised gain or loss. A currency without a rate within the 5-day window is reported with rate_missing and no current value. Amounts are decimal strings.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'as_of, functional_currency, totals and items')])]
    public function show(ExposureRequest $request, GetCurrencyExposureHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        $asOf = (string) ($request->validated('as_of') ?? CarbonImmutable::today()->toDateString());

        return $this->successResponse($handler->handle(new GetCurrencyExposureQuery($this->tenantId(), $asOf)), $this->message('exposure'));
    }
}
