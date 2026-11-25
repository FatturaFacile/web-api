<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\AuditAnswersRequest;
use Modules\FranchiseNetwork\Http\Requests\AuditPhotoRequest;
use Modules\FranchiseNetwork\Http\Requests\CompleteActionRequest;
use Modules\FranchiseNetwork\Http\Requests\ListFiltersRequest;
use Modules\FranchiseNetwork\Http\Requests\StartAuditRequest;
use Modules\FranchiseNetwork\Http\Resources\AuditActionResource;
use Modules\FranchiseNetwork\Http\Resources\AuditResource;
use Modules\FranchiseNetwork\Models\AuditAction;
use Modules\FranchiseNetwork\Models\AuditTemplate;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\StoreAudit;
use Modules\FranchiseNetwork\Services\AuditService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Store audits by the area manager. Area managers see and audit only the stores assigned to them; the franchise director sees all.
 */
final class AuditController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly AuditService $audits) {}

    #[OA\Get(path: '/api/v1/franchise-network/audits', operationId: 'franchiseNetworkListAudits', summary: 'Audit history of the stores', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Audits, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('audit', StoreAudit::class);

        $page = StoreAudit::query()->with(['store', 'template'])
            ->whereIn('store_id', $this->visibleStores(FranchiseStore::query())->select('id'))
            ->when($request->input('store_id'), fn ($rows, $store) => $rows->where('store_id', $store))
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->orderByDesc('audited_on')->orderByDesc('created_at')
            ->paginate($this->perPage(25));

        return $this->paged($page, AuditResource::class);
    }

    #[OA\Get(path: '/api/v1/franchise-network/audits/{id}', operationId: 'franchiseNetworkShowAudit', summary: 'Show an audit with its answers, photos and corrective actions', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Audit')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new AuditResource($this->find($id)->load(['store', 'template', 'actions'])), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/audits', operationId: 'franchiseNetworkStartAudit', summary: 'Start the audit of a store with a checklist template', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 201, description: 'Audit started')])]
    public function store(StartAuditRequest $request): JsonResponse
    {
        Gate::authorize('audit', StoreAudit::class);
        $data = $request->validated();
        $store = $this->visibleStores(FranchiseStore::query())->findOrFail($data['store_id']);
        $template = AuditTemplate::query()->findOrFail($data['template_id']);
        $audit = $this->audits->start($store, $template, $this->userId(), $data['audited_on'] ?? null);

        return $this->successResponse(new AuditResource($audit->load(['store', 'template'])), $this->message('audit_started'), 201);
    }

    #[OA\Put(path: '/api/v1/franchise-network/audits/{id}/answers', operationId: 'franchiseNetworkSaveAuditAnswers', summary: 'Save scores and notes of a draft audit', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Answers saved')])]
    public function answers(AuditAnswersRequest $request, string $id): JsonResponse
    {
        $audit = $this->audits->saveAnswers($this->find($id), $request->validated('answers'), $request->validated('notes'), $this->userId());

        return $this->successResponse(new AuditResource($audit->load(['store', 'template'])), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/audits/{id}/photos', operationId: 'franchiseNetworkAddAuditPhoto', summary: 'Attach a photo to a draft audit', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Photo attached')])]
    public function addPhoto(AuditPhotoRequest $request, string $id): JsonResponse
    {
        $audit = $this->find($id);
        $this->audits->addPhoto($audit, $request->file('photo'), $request->validated('criterion_code'), $request->validated('caption'), $this->userId());

        return $this->successResponse(new AuditResource($audit->refresh()->load(['store', 'template'])), $this->message('saved'), 201);
    }

    #[OA\Delete(path: '/api/v1/franchise-network/audits/{id}/photos/{photoId}', operationId: 'franchiseNetworkRemoveAuditPhoto', summary: 'Remove a photo from a draft audit', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'photoId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Photo removed')])]
    public function removePhoto(string $id, string $photoId): JsonResponse
    {
        $audit = $this->audits->removePhoto($this->find($id), $photoId, $this->userId());

        return $this->successResponse(new AuditResource($audit->load(['store', 'template'])), $this->message('deleted'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/audits/{id}/photos/{photoId}', operationId: 'franchiseNetworkShowAuditPhoto', summary: 'Download a photo of an audit', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'photoId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Image')])]
    public function photo(string $id, string $photoId): BinaryFileResponse
    {
        $photo = $this->audits->photo($this->find($id), $photoId);

        return response()->file($photo['path'], ['Content-Type' => $photo['mime'] ?? 'application/octet-stream', 'Content-Disposition' => 'inline; filename="'.addslashes($photo['name']).'"']);
    }

    #[OA\Post(path: '/api/v1/franchise-network/audits/{id}/submit', operationId: 'franchiseNetworkSubmitAudit', summary: 'Score and submit an audit, opening corrective actions for the weak criteria', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Audit submitted')])]
    public function submit(string $id): JsonResponse
    {
        $audit = $this->audits->submit($this->find($id), $this->userId());

        return $this->successResponse(new AuditResource($audit->load(['store', 'template', 'actions'])), $this->message('audit_submitted'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/audit-actions', operationId: 'franchiseNetworkListAuditActions', summary: 'Corrective actions opened by the audits', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Actions, paginated')])]
    public function actions(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('audit', StoreAudit::class);

        $page = AuditAction::query()
            ->whereIn('store_id', $this->visibleStores(FranchiseStore::query())->select('id'))
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->when($request->input('store_id'), fn ($rows, $store) => $rows->where('store_id', $store))
            ->orderBy('due_date')
            ->paginate($this->perPage(50));

        return $this->paged($page, AuditActionResource::class);
    }

    #[OA\Post(path: '/api/v1/franchise-network/audit-actions/{id}/complete', operationId: 'franchiseNetworkCompleteAuditAction', summary: 'Close a corrective action', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Action closed')])]
    public function completeAction(CompleteActionRequest $request, string $id): JsonResponse
    {
        Gate::authorize('audit', StoreAudit::class);
        $action = AuditAction::query()->whereIn('store_id', $this->visibleStores(FranchiseStore::query())->select('id'))->findOrFail($id);

        return $this->successResponse(new AuditActionResource($this->audits->completeAction($action, $request->validated('completion_note'), $this->userId())), $this->message('saved'));
    }

    private function find(string $id): StoreAudit
    {
        Gate::authorize('audit', StoreAudit::class);

        return StoreAudit::query()->whereIn('store_id', $this->visibleStores(FranchiseStore::query())->select('id'))->findOrFail($id);
    }
}
