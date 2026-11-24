<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\MarketingAutomation\Application\Commands\EvaluateRfmSegmentationAndBuildAudienceCommand;
use Modules\MarketingAutomation\Application\Commands\EvaluateRfmSegmentationAndBuildAudienceHandler;
use Modules\MarketingAutomation\Application\Queries\GetRfmMatrixHandler;
use Modules\MarketingAutomation\Application\Queries\GetRfmMatrixQuery;
use Modules\MarketingAutomation\Http\Controllers\Concerns\ResolvesMarketingContext;
use Modules\MarketingAutomation\Http\Requests\SaveSegmentRequest;
use Modules\MarketingAutomation\Http\Resources\SegmentResource;
use Modules\MarketingAutomation\Models\Segment;
use Modules\MarketingAutomation\Services\SegmentEvaluationService;
use OpenApi\Attributes as OA;

/**
 * Dynamic RFM customer segments: CRUD, evaluation, live preview and the RFM matrix.
 */
final class SegmentController extends Controller
{
    use ApiResponse;
    use ResolvesMarketingContext;

    #[OA\Get(path: '/api/v1/marketing-automation/segments', operationId: 'marketingListSegments', summary: 'List dynamic customer segments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Segments')])]
    public function index(): JsonResponse
    {
        return $this->successResponse(SegmentResource::collection(Segment::query()->where('tenant_id', $this->tenantId())->orderBy('name')->get()));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/segments/{id}', operationId: 'marketingShowSegment', summary: 'Show a segment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Segment')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new SegmentResource(Segment::query()->where('tenant_id', $this->tenantId())->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/marketing-automation/segments', operationId: 'marketingStoreSegment', summary: 'Create a segment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 201, description: 'Segment created')])]
    public function store(SaveSegmentRequest $request, EvaluateRfmSegmentationAndBuildAudienceHandler $evaluate): JsonResponse
    {
        $segment = Segment::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId(), 'created_by' => $this->userId($request)]);
        $evaluate->handle(new EvaluateRfmSegmentationAndBuildAudienceCommand($this->tenantId(), (string) $segment->id));

        return $this->successResponse(new SegmentResource($segment->refresh()), 'Segment created', 201);
    }

    #[OA\Put(path: '/api/v1/marketing-automation/segments/{id}', operationId: 'marketingUpdateSegment', summary: 'Update a segment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Segment updated')])]
    public function update(SaveSegmentRequest $request, string $id, EvaluateRfmSegmentationAndBuildAudienceHandler $evaluate): JsonResponse
    {
        $segment = Segment::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $segment->fill([...$request->validated(), 'updated_by' => $this->userId($request)])->save();
        $evaluate->handle(new EvaluateRfmSegmentationAndBuildAudienceCommand($this->tenantId(), (string) $segment->id));

        return $this->successResponse(new SegmentResource($segment->refresh()));
    }

    #[OA\Delete(path: '/api/v1/marketing-automation/segments/{id}', operationId: 'marketingDeleteSegment', summary: 'Delete a segment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Segment deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Segment::query()->where('tenant_id', $this->tenantId())->findOrFail($id)->delete();

        return $this->successResponse(null, 'Segment deleted');
    }

    #[OA\Post(path: '/api/v1/marketing-automation/segments/{id}/evaluate', operationId: 'marketingEvaluateSegment', summary: 'Recompute RFM scores and the audience of a segment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Segment evaluated')])]
    public function evaluate(string $id, EvaluateRfmSegmentationAndBuildAudienceHandler $evaluate): JsonResponse
    {
        $segments = $evaluate->handle(new EvaluateRfmSegmentationAndBuildAudienceCommand($this->tenantId(), $id));

        return $this->successResponse(new SegmentResource($segments->firstOrFail()));
    }

    #[OA\Post(path: '/api/v1/marketing-automation/segments/preview', operationId: 'marketingPreviewSegment', summary: 'Live audience count for unsaved criteria', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Eligible recipients and exclusions')])]
    public function preview(SaveSegmentRequest $request, SegmentEvaluationService $evaluation): JsonResponse
    {
        $draft = new Segment(['tenant_id' => $this->tenantId(), 'criteria' => (array) $request->validated('criteria', [])]);
        $result = $evaluation->compute($draft);

        return $this->successResponse([
            'eligible_contacts_count' => $result['eligible'],
            'excluded_insolvent_count' => $result['excluded_insolvent'],
            'excluded_no_consent_count' => $result['excluded_no_consent'],
            'rfm_breakdown' => $result['breakdown'],
        ]);
    }

    #[OA\Get(path: '/api/v1/marketing-automation/segments/{id}/rfm-matrix', operationId: 'marketingSegmentRfmMatrix', summary: 'RFM matrix of a segment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'RFM matrix')])]
    public function rfmMatrix(string $id, GetRfmMatrixHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetRfmMatrixQuery($this->tenantId(), $id)));
    }
}
