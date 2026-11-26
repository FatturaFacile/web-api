<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Treasury\Currency\Application\Commands\CreateManualRateCommand;
use Modules\Treasury\Currency\Application\Handlers\CreateManualRateHandler;
use Modules\Treasury\Currency\Application\Handlers\GetRateHandler;
use Modules\Treasury\Currency\Application\Handlers\ListRatesHandler;
use Modules\Treasury\Currency\Application\Queries\GetRateQuery;
use Modules\Treasury\Currency\Application\Queries\ListRatesQuery;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Http\Controllers\Concerns\InteractsWithCurrency;
use Modules\Treasury\Currency\Http\Requests\ExportRatesRequest;
use Modules\Treasury\Currency\Http\Requests\ListRatesRequest;
use Modules\Treasury\Currency\Http\Requests\ResolveRateRequest;
use Modules\Treasury\Currency\Http\Requests\StoreManualRateRequest;
use Modules\Treasury\Currency\Http\Resources\ExchangeRateResource;
use Modules\Treasury\Currency\Infrastructure\Export\RatesAuditExporter;
use OpenApi\Attributes as OA;

/**
 * Exchange rates: the list the company sees (official and its own), the rate that applies to a pair on a day, manual and contract
 * rates, and the audit export. Rates and amounts are decimal strings, days `Y-m-d`.
 */
final class CurrencyRateController extends Controller
{
    use ApiResponse;
    use InteractsWithCurrency;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/currencies/rates', operationId: 'currencyRatesIndex', summary: 'List exchange rates', description: 'Official shared rates (no tenant) and the manual or contract rates of the company, newest day first. Superseded versions are not listed. Filters: base, quote, rate_type, from, to.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'Paginated rates: base, quote, rate (decimal string, 6 decimals), rate_type, source, valid_on (Y-m-d), is_derived, is_contract, version, superseded_by, tenant_id, reason, contract_reference')])]
    public function index(ListRatesRequest $request, ListRatesHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        return $this->paged($handler->handle(new ListRatesQuery(
            tenantId: $this->tenantId(),
            base: $request->validated('base'),
            quote: $request->validated('quote'),
            type: $request->validated('rate_type') === null ? null : RateType::from((string) $request->validated('rate_type')),
            from: $request->validated('from'),
            to: $request->validated('to'),
            perPage: (int) $request->validated('per_page', 25),
        )), ExchangeRateResource::class);
    }

    #[OA\Get(path: '/api/v1/currencies/rates/resolve', operationId: 'currencyRatesResolve', summary: 'Resolve the rate of a pair on a day', description: 'The rate of the day, or the latest earlier one up to 5 days back (weekends, holidays), directly, inverted or as a cross rate through EUR (flagged is_derived). The contract rate of a contract_reference when given. Same currency: 1.000000. Beyond 5 days: 422 with code exchange_rate_missing, never an invented rate.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'The rate: base, quote, rate, rate_type, source, valid_on, requested_date, is_derived, is_contract, is_fallback_date, rate_id'), new OA\Response(response: 422, description: 'exchange_rate_missing')])]
    public function resolve(ResolveRateRequest $request, GetRateHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        try {
            $rate = $handler->handle(new GetRateQuery(
                tenantId: $this->tenantId(),
                base: (string) $request->validated('base'),
                quote: (string) $request->validated('quote'),
                date: (string) $request->validated('date'),
                type: RateType::from((string) $request->validated('rate_type', RateType::Mid->value)),
                contractReference: $request->validated('contract_reference'),
            ));
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        return $this->successResponse($rate->toArray(), $this->message('resolved'));
    }

    #[OA\Post(path: '/api/v1/currencies/rates', operationId: 'currencyRatesStore', summary: 'Enter a manual or contract rate', description: 'A manual rate or a rate fixed for a contract, an order or a document (rate_type contract, reason mandatory). A rate is never edited: a new value for the same pair, type and day is a new version and the previous one points to it (superseded_by).', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 201, description: 'Rate stored'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(StoreManualRateRequest $request, CreateManualRateHandler $handler): JsonResponse
    {
        Gate::authorize('create', ExchangeRate::class);

        try {
            $record = $handler->handle(new CreateManualRateCommand(
                tenantId: $this->tenantId(),
                base: (string) $request->validated('base'),
                quote: (string) $request->validated('quote'),
                rate: (string) $request->validated('rate'),
                validOn: (string) $request->validated('valid_on'),
                type: RateType::from((string) $request->validated('rate_type', RateType::Mid->value)),
                reason: $request->validated('reason'),
                contractReference: $request->validated('contract_reference'),
                actorId: $this->userId(),
            ));
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        return $this->successResponse(new ExchangeRateResource($record), $this->message('rate_stored'), 201);
    }

    #[OA\Get(path: '/api/v1/currencies/rates/export', operationId: 'currencyRatesExport', summary: 'Audit export of the exchange rates of a period', description: 'CSV (default) or XLSX of every rate of the company in the period, superseded versions included. Rates are exact decimal strings.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function export(ExportRatesRequest $request, RatesAuditExporter $exporter): Response
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        $export = $exporter->export($this->tenantId(), (string) $request->validated('from'), (string) $request->validated('to'), (string) $request->validated('format', 'csv'));

        return response($export['content'], 200, [
            'Content-Type' => $export['content_type'],
            'Content-Disposition' => "attachment; filename=\"{$export['filename']}\"",
        ]);
    }
}
