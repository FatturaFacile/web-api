<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Commands\ReverseAccrualRunCommand;
use Modules\GdoTradeTerms\Application\Commands\ReverseAccrualRunHandler;
use Modules\GdoTradeTerms\Application\Commands\RunMonthlyAccrualCommand;
use Modules\GdoTradeTerms\Application\Commands\RunMonthlyAccrualHandler;
use Modules\GdoTradeTerms\Application\Queries\GetAccrualRunDetailHandler;
use Modules\GdoTradeTerms\Application\Queries\GetAccrualRunDetailQuery;
use Modules\GdoTradeTerms\Application\Queries\GetAccrualRunsHandler;
use Modules\GdoTradeTerms\Application\Queries\GetAccrualRunsQuery;
use Modules\GdoTradeTerms\Application\Queries\PreviewMonthlyAccrualHandler;
use Modules\GdoTradeTerms\Application\Queries\PreviewMonthlyAccrualQuery;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\AccrualPeriodRequest;
use Modules\GdoTradeTerms\Http\Requests\ListFiltersRequest;
use Modules\GdoTradeTerms\Http\Requests\ReverseRunRequest;
use Modules\GdoTradeTerms\Http\Resources\AccrualRunResource;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;
use OpenApi\Attributes as OA;

/**
 * Monthly accrual runs (stanziamento ratei mensili): preview, post, read the ledger and reverse.
 */
final class AccrualController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/accrual-runs', operationId: 'gdoTradeTermsListAccrualRuns', summary: 'List the monthly accrual runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Runs, paginated')])]
    public function index(ListFiltersRequest $request, GetAccrualRunsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoAccrualRun::class);

        return $this->paged($handler->handle(new GetAccrualRunsQuery($request->input('status'), $this->perPage())), AccrualRunResource::class);
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/accrual-runs/preview', operationId: 'gdoTradeTermsPreviewAccrual', summary: 'What a monthly accrual would add, without posting', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Accrual preview')])]
    public function preview(AccrualPeriodRequest $request, PreviewMonthlyAccrualHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoAccrualRun::class);

        return $this->successResponse($handler->handle(new PreviewMonthlyAccrualQuery($this->tenantId(), (string) $request->validated('period').'-01')));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/accrual-runs', operationId: 'gdoTradeTermsRunAccrual', summary: 'Close a month: accrue the rebates and contributions and post the entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 201, description: 'Accrual posted (or the posted run returned)')])]
    public function store(AccrualPeriodRequest $request, RunMonthlyAccrualHandler $handler): JsonResponse
    {
        Gate::authorize('settle', GdoAccrualRun::class);

        $result = $handler->handle(new RunMonthlyAccrualCommand($this->tenantId(), (string) $request->validated('period').'-01', (bool) $request->validated('recalculate', false), $this->userId()));
        $run = $result['run'];

        return $this->successResponse([...$result, 'run' => $run === null ? null : new AccrualRunResource($run)], $this->message($result['status'] === 'already_posted' ? 'already_posted' : 'accrued'), $result['status'] === 'already_posted' ? 200 : 201);
    }

    #[OA\Get(path: '/api/v1/gdo-trade-terms/accrual-runs/{id}', operationId: 'gdoTradeTermsShowAccrualRun', summary: 'An accrual run with its ledger lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Run detail')])]
    public function show(string $id, GetAccrualRunDetailHandler $handler): JsonResponse
    {
        $run = $handler->handle(new GetAccrualRunDetailQuery($id));
        Gate::authorize('view', $run);

        return $this->successResponse(new AccrualRunResource($run));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/accrual-runs/{id}/reverse', operationId: 'gdoTradeTermsReverseAccrualRun', summary: 'Reverse the latest posted accrual run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Run reversed')])]
    public function reverse(ReverseRunRequest $request, string $id, ReverseAccrualRunHandler $handler): JsonResponse
    {
        Gate::authorize('settle', GdoAccrualRun::class);
        GdoAccrualRun::query()->findOrFail($id);

        $run = $handler->handle(new ReverseAccrualRunCommand($id, (string) $request->validated('reason'), $this->userId()));

        return $this->successResponse(new AccrualRunResource($run->load('lines')), $this->message('reversed'));
    }
}
