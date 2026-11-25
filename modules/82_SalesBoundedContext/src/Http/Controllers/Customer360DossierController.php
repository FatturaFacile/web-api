<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\UpsertCustomer360DossierCommand;
use Modules\SalesBoundedContext\Application\Commands\UpsertCustomer360DossierHandler;
use Modules\SalesBoundedContext\Application\Queries\GetCustomer360SummaryHandler;
use Modules\SalesBoundedContext\Application\Queries\GetCustomer360SummaryQuery;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\UpsertCustomerDossierRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use OpenApi\Attributes as OA;

final class Customer360DossierController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly UpsertCustomer360DossierHandler $upsertHandler,
        private readonly GetCustomer360SummaryHandler $summaryHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/dossiers',
        operationId: 'listSbcCustomerDossiers',
        summary: 'List Customer & Contact 360° dossiers (Mod 033)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Customer dossiers')]
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcCustomerDossier::class);

        return SbcRecordResource::collection(
            SbcCustomerDossier::query()
                ->where('tenant_id', $this->tenantId())
                ->orderBy('company_name')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/dossiers',
        operationId: 'storeSbcCustomerDossier',
        summary: 'Create a Customer 360° dossier with CH UID / IT P.IVA/CF/SDI/PEC validation (Mod 033)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Customer dossier created')]
    )]
    public function store(UpsertCustomerDossierRequest $request): JsonResponse
    {
        $this->authorize('create', SbcCustomerDossier::class);
        $dossier = $this->upsertHandler->handle(new UpsertCustomer360DossierCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($dossier))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/dossiers/{id}/summary',
        operationId: 'showSbcCustomerDossierSummary',
        summary: 'Get 360° summary view for a customer dossier (Mod 033)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Customer 360 summary')]
    )]
    public function summary(string $id): JsonResponse
    {
        $dossier = SbcCustomerDossier::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $dossier);

        return response()->json([
            'data' => $this->summaryHandler->handle(new GetCustomer360SummaryQuery($this->tenantId(), $id)),
        ]);
    }
}
