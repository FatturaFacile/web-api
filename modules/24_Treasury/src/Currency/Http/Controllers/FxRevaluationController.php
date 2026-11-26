<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Treasury\Currency\Application\Commands\PostFxRevaluationCommand;
use Modules\Treasury\Currency\Application\Commands\ReverseFxRevaluationCommand;
use Modules\Treasury\Currency\Application\Commands\SimulateFxRevaluationCommand;
use Modules\Treasury\Currency\Application\Handlers\GetRevaluationPreviewHandler;
use Modules\Treasury\Currency\Application\Handlers\PostFxRevaluationHandler;
use Modules\Treasury\Currency\Application\Handlers\ReverseFxRevaluationHandler;
use Modules\Treasury\Currency\Application\Handlers\SimulateFxRevaluationHandler;
use Modules\Treasury\Currency\Application\Queries\GetRevaluationPreviewQuery;
use Modules\Treasury\Currency\Domain\Enums\RevaluationPeriodType;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Http\Controllers\Concerns\InteractsWithCurrency;
use Modules\Treasury\Currency\Http\Requests\PostRevaluationRequest;
use Modules\Treasury\Currency\Http\Requests\ReverseRevaluationRequest;
use Modules\Treasury\Currency\Http\Requests\SimulateRevaluationRequest;
use Modules\Treasury\Currency\Http\Resources\FxRevaluationResource;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use OpenApi\Attributes as OA;

/**
 * Period-end revaluation of the foreign-currency items: simulate, inspect, post (idempotent) and reverse.
 */
final class FxRevaluationController extends Controller
{
    use ApiResponse;
    use InteractsWithCurrency;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/accounting/fx-revaluations', operationId: 'fxRevaluationsIndex', summary: 'List the FX revaluations', description: 'Revaluations of the company, newest period first, without their lines.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 200, description: 'Paginated revaluations')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', CurrencyFxRevaluation::class);

        $page = CurrencyFxRevaluation::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId())
            ->whereNull('deleted_at')
            ->orderByDesc('period_end')
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) request()->query('per_page', 25))));

        return $this->paged($page, FxRevaluationResource::class);
    }

    #[OA\Post(path: '/api/v1/accounting/fx-revaluations', operationId: 'fxRevaluationsSimulate', summary: 'Simulate a period-end FX revaluation', description: 'Values the open receivables and payables and the foreign-currency bank and cash accounts at the closing rate of period_end (month end; quarter end for quarterly) and keeps the result as a draft. Difference = currency balance × closing rate − functional book value; a positive difference is a gain. 422 exchange_rate_missing when a currency has no closing rate within the 5-day window.', security: self::SECURITY, tags: ['Currencies'], responses: [new OA\Response(response: 201, description: 'Draft revaluation with its lines'), new OA\Response(response: 422, description: 'Validation or exchange_rate_missing')])]
    public function store(SimulateRevaluationRequest $request, SimulateFxRevaluationHandler $handler): JsonResponse
    {
        Gate::authorize('simulate', CurrencyFxRevaluation::class);

        try {
            $revaluation = $handler->handle(new SimulateFxRevaluationCommand(
                tenantId: $this->tenantId(),
                periodEnd: (string) $request->validated('period_end'),
                periodType: RevaluationPeriodType::from((string) $request->validated('period_type')),
                currencies: $request->validated('currencies') === null ? null : array_values((array) $request->validated('currencies')),
                actorId: $this->userId(),
            ));
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        return $this->successResponse(new FxRevaluationResource($revaluation), $this->message('revaluation_simulated'), 201);
    }

    #[OA\Get(path: '/api/v1/accounting/fx-revaluations/{id}', operationId: 'fxRevaluationsShow', summary: 'Preview or read an FX revaluation', description: 'The revaluation with its lines: currency balance, book value, closing rate, revalued value, difference and result per position.', security: self::SECURITY, tags: ['Currencies'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The revaluation'), new OA\Response(response: 404, description: 'Not found')])]
    public function show(string $id, GetRevaluationPreviewHandler $handler): JsonResponse
    {
        try {
            $revaluation = $handler->handle(new GetRevaluationPreviewQuery($this->tenantId(), $id));
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        Gate::authorize('view', $revaluation);

        return $this->successResponse(new FxRevaluationResource($revaluation), $this->message('revaluation'));
    }

    #[OA\Post(path: '/api/v1/accounting/fx-revaluations/{id}/post', operationId: 'fxRevaluationsPost', summary: 'Post an FX revaluation to the ledger', description: 'Requires the Idempotency-Key header (8 to 100 characters). The same key on a posted revaluation answers 200 again; another key on a posted revaluation, or a period already posted, answers 409. Losses are always booked; gains follow the company treatment. When the company requires four-eyes approval of journals, the author of the simulation cannot post it.', security: self::SECURITY, tags: ['Currencies'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Posted'), new OA\Response(response: 409, description: 'Already posted'), new OA\Response(response: 422, description: 'Missing key or account')])]
    public function post(string $id, PostRevaluationRequest $request, GetRevaluationPreviewHandler $preview, PostFxRevaluationHandler $handler): JsonResponse
    {
        $key = $request->idempotencyKey();

        if ($key === null) {
            return $this->failure(new CurrencyDomainException(__('currency_fx.errors.idempotency_key_required'), 'idempotency_key_required', 422, 'idempotency_key'));
        }

        try {
            $revaluation = $preview->handle(new GetRevaluationPreviewQuery($this->tenantId(), $id));
            Gate::authorize('post', $revaluation);
            $posted = $handler->handle(new PostFxRevaluationCommand($this->tenantId(), $id, $key, $this->userId()));
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        return $this->successResponse(new FxRevaluationResource($posted), $this->message('revaluation_posted'));
    }

    #[OA\Post(path: '/api/v1/accounting/fx-revaluations/{id}/reverse', operationId: 'fxRevaluationsReverse', summary: 'Reverse a posted FX revaluation', description: 'Reverses the entry on the first day of the next period (or on reversal_date). The scheduled job does this automatically. 409 when the revaluation is not posted.', security: self::SECURITY, tags: ['Currencies'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Reversed'), new OA\Response(response: 409, description: 'Not posted')])]
    public function reverse(string $id, ReverseRevaluationRequest $request, GetRevaluationPreviewHandler $preview, ReverseFxRevaluationHandler $handler): JsonResponse
    {
        try {
            $revaluation = $preview->handle(new GetRevaluationPreviewQuery($this->tenantId(), $id));
            Gate::authorize('reverse', $revaluation);
            $reversed = $handler->handle(new ReverseFxRevaluationCommand($this->tenantId(), $id, $request->validated('reversal_date'), $this->userId()));
        } catch (CurrencyDomainException $exception) {
            return $this->failure($exception);
        }

        return $this->successResponse(new FxRevaluationResource($reversed), $this->message('revaluation_reversed'));
    }
}
