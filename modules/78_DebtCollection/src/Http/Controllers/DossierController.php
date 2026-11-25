<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\DebtCollection\Application\Commands\GenerateDossierCommand;
use Modules\DebtCollection\Application\Commands\GenerateDossierHandler;
use Modules\DebtCollection\Application\Commands\IssueMessaInMoraCommand;
use Modules\DebtCollection\Application\Commands\IssueMessaInMoraHandler;
use Modules\DebtCollection\Application\Queries\GetCustomerHistoryHandler;
use Modules\DebtCollection\Application\Queries\GetCustomerHistoryQuery;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Http\Controllers\Concerns\ResolvesDebtCollectionContext;
use Modules\DebtCollection\Http\Requests\DossierRequest;
use Modules\DebtCollection\Http\Requests\MessaInMoraRequest;
use Modules\DebtCollection\Http\Resources\DossierResource;
use Modules\DebtCollection\Http\Resources\EventResource;
use Modules\DebtCollection\Http\Resources\ExclusionResource;
use Modules\DebtCollection\Http\Resources\NoticeResource;
use Modules\DebtCollection\Http\Resources\PaymentPlanResource;
use Modules\DebtCollection\Models\Dossier;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\DossierService;
use Modules\DebtCollection\Services\ListingService;
use OpenApi\Attributes as OA;

/**
 * The legal steps: the Swiss Betreibung dossier (request form, chronology, invoices and QR-bill slips), the Italian
 * messa in mora by PEC, and the dunning history shown in the customer master record.
 */
final class DossierController extends Controller
{
    use ApiResponse;
    use ResolvesDebtCollectionContext;

    public function __construct(
        private readonly ListingService $listing,
        private readonly GenerateDossierHandler $generate,
        private readonly IssueMessaInMoraHandler $messaInMora,
        private readonly GetCustomerHistoryHandler $history,
        private readonly DossierService $dossiers,
    ) {}

    #[OA\Get(path: '/api/v1/debt-collection/dossiers', operationId: 'debtCollectionListDossiers', summary: 'List the Betreibung dossiers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Dossiers')])]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);
        $page = $this->listing->dossiers($request->query('customer_id') === null ? null : (string) $request->query('customer_id'), 25);

        return $this->successResponse(DossierResource::collection($page->items()), 'OK', 200, $this->pageMeta($page));
    }

    #[OA\Post(path: '/api/v1/debt-collection/dossiers', operationId: 'debtCollectionGenerateDossier', summary: 'Generate the Betreibung dossier of a customer in one step', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Dossier')])]
    public function store(DossierRequest $request): JsonResponse
    {
        $data = $request->validated();
        $dossier = $this->generate->handle(new GenerateDossierCommand($this->tenantId(), $data['customer_id'], $data['receivable_item_ids'], $data['office_id'] ?? null, $data['locale'] ?? null, $this->asOf($data['as_of'] ?? null), $this->userId($request)));

        return $this->successResponse(new DossierResource($dossier->load('customer')), 'Dossier generated', 201);
    }

    #[OA\Get(path: '/api/v1/debt-collection/dossiers/{id}', operationId: 'debtCollectionShowDossier', summary: 'Show a dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse(new DossierResource(Dossier::query()->with('customer')->findOrFail($id)));
    }

    #[OA\Get(path: '/api/v1/debt-collection/dossiers/{id}/pdf', operationId: 'debtCollectionDossierPdf', summary: 'Download the Betreibungsbegehren PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF document')])]
    public function pdf(string $id): Response
    {
        return $this->file($id, 'pdf_path', 'application/pdf', 'pdf');
    }

    #[OA\Get(path: '/api/v1/debt-collection/dossiers/{id}/bundle', operationId: 'debtCollectionDossierBundle', summary: 'Download the dossier bundle (request, invoices, QR-bill slips, notices)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'ZIP archive')])]
    public function bundle(string $id): Response
    {
        return $this->file($id, 'bundle_path', 'application/zip', 'zip');
    }

    #[OA\Post(path: '/api/v1/debt-collection/dossiers/{id}/submit', operationId: 'debtCollectionSubmitDossier', summary: 'Mark a dossier as submitted to the enforcement office', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dossier')])]
    public function submit(Request $request, string $id): JsonResponse
    {
        Gate::authorize('escalate', Notice::class);

        return $this->successResponse(new DossierResource($this->dossiers->submit(Dossier::query()->findOrFail($id), $this->userId($request))->load('customer')));
    }

    #[OA\Post(path: '/api/v1/debt-collection/messa-in-mora', operationId: 'debtCollectionMessaInMora', summary: 'Generate the Italian messa in mora (D.Lgs. 231/2002) and optionally send it by PEC', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Notice')])]
    public function messaInMora(MessaInMoraRequest $request): JsonResponse
    {
        $data = $request->validated();
        $notice = $this->messaInMora->handle(new IssueMessaInMoraCommand($this->tenantId(), $data['customer_id'], $data['receivable_item_ids'], (bool) ($data['send_pec'] ?? false), $data['locale'] ?? null, $this->asOf($data['as_of'] ?? null), $this->userId($request)));

        return $this->successResponse(new NoticeResource($notice->load('customer')), 'Messa in mora generated', 201);
    }

    #[OA\Get(path: '/api/v1/debt-collection/customers/{customerId}/history', operationId: 'debtCollectionCustomerHistory', summary: 'Dunning and recovery history of a customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'History')])]
    public function customerHistory(string $customerId): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);
        $history = $this->history->handle(new GetCustomerHistoryQuery($customerId, $this->asOf(null)));

        return $this->successResponse([
            'customer' => $history['customer'],
            'summary' => $history['summary'],
            'notices' => NoticeResource::collection($history['notices']),
            'dossiers' => DossierResource::collection($history['dossiers']),
            'exclusions' => ExclusionResource::collection($history['exclusions']),
            'plans' => PaymentPlanResource::collection($history['plans']),
            'timeline' => EventResource::collection($history['timeline']),
        ]);
    }

    private function file(string $id, string $column, string $mime, string $extension): Response
    {
        Gate::authorize('viewAny', Notice::class);
        $dossier = Dossier::query()->findOrFail($id);
        $path = (string) $dossier->{$column};

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            throw new DebtCollectionException('The dossier file is no longer available.', 'file_missing', 404);
        }

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$dossier->number.'.'.$extension.'"']);
    }
}
