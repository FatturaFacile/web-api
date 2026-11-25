<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Http\Controllers\Concerns\InteractsWithTenders;
use Modules\TenderManagement\Http\Requests\IngestSimapRequest;
use Modules\TenderManagement\Http\Requests\ListFiltersRequest;
use Modules\TenderManagement\Http\Requests\SaveNoticeRequest;
use Modules\TenderManagement\Http\Resources\NoticeResource;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Services\NoticeService;
use OpenApi\Attributes as OA;

/**
 * Tender notices: simap.ch, ANAC / MePA, EU TED and private requests for proposal.
 */
final class NoticeController extends Controller
{
    use ApiResponse;
    use InteractsWithTenders;

    #[OA\Get(path: '/api/v1/tender-management/notices', operationId: 'tenderManagementListNotices', summary: 'List tender notices', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Tender notices, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', TenderNotice::class);

        $page = TenderNotice::query()
            ->withCount('participations')
            ->when($request->filled('jurisdiction'), fn ($query) => $query->where('jurisdiction', $request->input('jurisdiction')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.mb_strtolower((string) $request->input('q')).'%';
                $query->where(fn ($inner) => $inner->whereRaw('LOWER(title) LIKE ?', [$term])->orWhereRaw('LOWER(contracting_authority_name) LIKE ?', [$term])->orWhereRaw('LOWER(COALESCE(cig_code_it, \'\')) LIKE ?', [$term])->orWhereRaw('LOWER(COALESCE(simap_project_number, \'\')) LIKE ?', [$term]));
            })
            ->orderBy('submission_peremptory_deadline_at')
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        return $this->paged($page, NoticeResource::class, $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/tender-management/notices', operationId: 'tenderManagementCreateNotice', summary: 'Create a tender notice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 201, description: 'Notice created')])]
    public function store(SaveNoticeRequest $request, NoticeService $notices): JsonResponse
    {
        Gate::authorize('create', TenderNotice::class);

        return $this->successResponse(new NoticeResource($notices->create($request->validated(), $this->userId())), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/tender-management/notices/ingest-simap', operationId: 'tenderManagementIngestSimap', summary: 'Create or refresh a notice from a simap.ch publication', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 201, description: 'Notice ingested')])]
    public function ingestSimap(IngestSimapRequest $request, NoticeService $notices): JsonResponse
    {
        Gate::authorize('create', TenderNotice::class);

        $notice = $notices->ingestSimap((array) $request->input('payload'), $this->userId(), app()->getLocale());

        return $this->successResponse(new NoticeResource($notice), $this->message('ingested'), 201, ['jurisdiction' => Jurisdiction::SwissSimap->value]);
    }

    #[OA\Get(path: '/api/v1/tender-management/notices/{id}', operationId: 'tenderManagementShowNotice', summary: 'Tender notice detail', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Notice detail')])]
    public function show(string $id): JsonResponse
    {
        $notice = TenderNotice::query()->withCount('participations')->findOrFail($id);
        Gate::authorize('view', $notice);

        return $this->successResponse(new NoticeResource($notice));
    }

    #[OA\Put(path: '/api/v1/tender-management/notices/{id}', operationId: 'tenderManagementUpdateNotice', summary: 'Update a tender notice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Notice updated')])]
    public function update(SaveNoticeRequest $request, string $id, NoticeService $notices): JsonResponse
    {
        $notice = TenderNotice::query()->findOrFail($id);
        Gate::authorize('update', $notice);

        return $this->successResponse(new NoticeResource($notices->update($notice, $request->validated(), $this->userId())), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/tender-management/notices/{id}', operationId: 'tenderManagementDeleteNotice', summary: 'Delete a tender notice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Notice deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $notice = TenderNotice::query()->findOrFail($id);
        Gate::authorize('delete', $notice);
        $notice->participations()->delete();
        $notice->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
