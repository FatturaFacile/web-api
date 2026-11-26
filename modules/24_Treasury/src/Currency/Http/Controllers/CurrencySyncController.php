<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Treasury\Currency\Application\Handlers\ListSyncRunsHandler;
use Modules\Treasury\Currency\Application\Handlers\RequestExchangeRateSyncHandler;
use Modules\Treasury\Currency\Application\Queries\ListSyncRunsQuery;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Http\Controllers\Concerns\InteractsWithCurrency;
use Modules\Treasury\Currency\Http\Requests\SyncRatesRequest;
use Modules\Treasury\Currency\Http\Resources\SyncRunResource;
use OpenApi\Attributes as OA;

/**
 * The manual "update now" of the rates and the history of the synchronisation runs.
 */
final class CurrencySyncController extends Controller
{
    use ApiResponse;
    use InteractsWithCurrency;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Post(path: '/api/v1/currencies/rates/sync', operationId: 'currencyRatesSync', summary: 'Update the exchange rates now', description: 'Queues a run per provider (ecb, snb, estv; default ecb and snb) and answers 202 with the queued runs. 409 when a provider is already queued or running. A failed run keeps no rate, is recorded with its error and the finance team is notified.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 202, description: 'Runs queued'), new OA\Response(response: 409, description: 'A synchronisation is already running')])]
    public function sync(SyncRatesRequest $request, RequestExchangeRateSyncHandler $handler): JsonResponse
    {
        Gate::authorize('sync', ExchangeRate::class);

        try {
            $runs = $handler->handle(
                $this->tenantId(),
                $this->userId(),
                array_map(static fn (string $provider): RateSource => RateSource::from($provider), (array) $request->validated('providers', [])),
            );
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        return $this->successResponse(['runs' => SyncRunResource::collection($runs)->resolve()], $this->message('sync_queued'), 202);
    }

    #[OA\Get(path: '/api/v1/currencies/sync-runs', operationId: 'currencySyncRuns', summary: 'History of the rate synchronisation runs', description: 'The scheduled runs of the platform and the manual runs of the company: provider, status (queued, running, succeeded, failed), trigger, times, rates and derived rates stored, error message.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'Paginated runs')])]
    public function runs(ListSyncRunsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        return $this->paged($handler->handle(new ListSyncRunsQuery($this->tenantId(), max(1, min(100, (int) request()->query('per_page', 25))))), SyncRunResource::class);
    }
}
