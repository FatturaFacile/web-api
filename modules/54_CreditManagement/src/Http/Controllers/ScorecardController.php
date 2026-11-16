<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Http\Requests\RatingBandRequest;
use Modules\CreditManagement\Http\Requests\ScorecardFactorRequest;
use Modules\CreditManagement\Http\Resources\RatingBandResource;
use Modules\CreditManagement\Http\Resources\ScorecardFactorResource;
use Modules\CreditManagement\Models\CreditRatingBand;
use Modules\CreditManagement\Models\CreditScorecardFactor;
use Modules\CreditManagement\Services\CreditScoringService;
use OpenApi\Attributes as OA;

/**
 * Scorecard factors and rating bands of the credit scoring.
 */
final class ScorecardController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly CreditScoringService $scoring) {}

    #[OA\Get(path: '/api/v1/credit-management/scorecard/factors', operationId: 'listCreditScorecardFactors', description: 'The factors of the credit scorecard, in calculation order.', summary: 'List scorecard factors', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List scorecard factors', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditScorecardFactor'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function factors(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditScorecardFactor::class);

        return ScorecardFactorResource::collection(CreditScorecardFactor::query()->where('tenant_id', $this->tenantId())->orderBy('sort_order')->orderBy('code')->get());
    }

    #[OA\Post(path: '/api/v1/credit-management/scorecard/factors', operationId: 'storeCreditScorecardFactor', description: 'Add a factor to the scorecard. The weight is relative: the score is the weighted average of the active factors. A manual factor is filled in by the analyst when scoring.', summary: 'Create a scorecard factor', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditScorecardFactorRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Create a scorecard factor', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditScorecardFactor')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function storeFactor(ScorecardFactorRequest $request): JsonResponse
    {
        $this->authorize('create', CreditScorecardFactor::class);

        return (new ScorecardFactorResource(CreditScorecardFactor::create([...$request->validated(), 'tenant_id' => $this->tenantId()])->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/credit-management/scorecard/factors/{id}', operationId: 'updateCreditScorecardFactor', description: 'Change a scorecard factor. Scores already stored keep the weights they were computed with.', summary: 'Update a scorecard factor', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditScorecardFactorRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Update a scorecard factor', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditScorecardFactor')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function updateFactor(ScorecardFactorRequest $request, string $id): ScorecardFactorResource
    {
        $factor = CreditScorecardFactor::query()->findOrFail($id);
        $this->authorize('update', $factor);
        $factor->update($request->validated());

        return new ScorecardFactorResource($factor->refresh());
    }

    #[OA\Delete(path: '/api/v1/credit-management/scorecard/factors/{id}', operationId: 'deleteCreditScorecardFactor', description: 'Delete a scorecard factor (soft delete).', summary: 'Delete a scorecard factor', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 204, description: 'Delete a scorecard factor'),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function destroyFactor(string $id): Response
    {
        $factor = CreditScorecardFactor::query()->findOrFail($id);
        $this->authorize('delete', $factor);
        $factor->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/credit-management/scorecard/bands', operationId: 'listCreditRatingBands', description: 'The rating bands, from the lowest score range to the highest.', summary: 'List rating bands', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List rating bands', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditRatingBand'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function bands(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditRatingBand::class);

        return RatingBandResource::collection(CreditRatingBand::query()->where('tenant_id', $this->tenantId())->orderBy('min_score')->get());
    }

    #[OA\Post(path: '/api/v1/credit-management/scorecard/bands', operationId: 'storeCreditRatingBand', description: 'Add a rating band. Score ranges (0-100) and codes cannot overlap with another band. The limit multiplier and ceiling drive the automatic limit proposal: multiplier times the quarterly turnover, capped by the ceiling (a ceiling of 0 proposes no credit).', summary: 'Create a rating band', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditRatingBandRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Create a rating band', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditRatingBand')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function storeBand(RatingBandRequest $request): JsonResponse
    {
        $this->authorize('create', CreditRatingBand::class);

        return (new RatingBandResource(CreditRatingBand::create([...$request->validated(), 'tenant_id' => $this->tenantId()])->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/credit-management/scorecard/bands/{id}', operationId: 'updateCreditRatingBand', description: 'Change a rating band.', summary: 'Update a rating band', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditRatingBandRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Update a rating band', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditRatingBand')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function updateBand(RatingBandRequest $request, string $id): RatingBandResource
    {
        $band = CreditRatingBand::query()->findOrFail($id);
        $this->authorize('update', $band);
        $band->update($request->validated());

        return new RatingBandResource($band->refresh());
    }

    #[OA\Delete(path: '/api/v1/credit-management/scorecard/bands/{id}', operationId: 'deleteCreditRatingBand', description: 'Delete a rating band (soft delete).', summary: 'Delete a rating band', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 204, description: 'Delete a rating band'),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function destroyBand(string $id): Response
    {
        $band = CreditRatingBand::query()->findOrFail($id);
        $this->authorize('delete', $band);
        $band->delete();

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/credit-management/scorecard/defaults', operationId: 'seedCreditScorecardDefaults', description: 'Create the default factors and rating bands (A to E). Refused when the company already has a factor or a band.', summary: 'Create the default scorecard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Create the default scorecard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditScorecardDefaults')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function defaults(): JsonResponse
    {
        $this->authorize('create', CreditScorecardFactor::class);

        return response()->json(['data' => $this->scoring->seedDefaults($this->tenantId())], Response::HTTP_CREATED);
    }
}
