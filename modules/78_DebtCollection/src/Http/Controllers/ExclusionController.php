<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\DebtCollection\Http\Controllers\Concerns\ResolvesDebtCollectionContext;
use Modules\DebtCollection\Http\Requests\ExclusionRequest;
use Modules\DebtCollection\Http\Requests\PaymentPlanRequest;
use Modules\DebtCollection\Http\Requests\ReleaseExclusionRequest;
use Modules\DebtCollection\Http\Resources\ExclusionResource;
use Modules\DebtCollection\Http\Resources\PaymentPlanResource;
use Modules\DebtCollection\Models\Exclusion;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\PaymentPlan;
use Modules\DebtCollection\Services\ExclusionService;
use Modules\DebtCollection\Services\ListingService;
use OpenApi\Attributes as OA;

/**
 * Manual exclusions of customers or items from dunning (dispute, legal hold, other) and payment plans (piano di rientro).
 */
final class ExclusionController extends Controller
{
    use ApiResponse;
    use ResolvesDebtCollectionContext;

    public function __construct(private readonly ExclusionService $exclusions, private readonly ListingService $listing) {}

    #[OA\Get(path: '/api/v1/debt-collection/exclusions', operationId: 'debtCollectionListExclusions', summary: 'List dunning exclusions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Exclusions')])]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);
        $customer = $request->query('customer_id') === null ? null : (string) $request->query('customer_id');

        return $this->successResponse(ExclusionResource::collection($this->listing->exclusions($customer, $request->boolean('active'))));
    }

    #[OA\Post(path: '/api/v1/debt-collection/exclusions', operationId: 'debtCollectionCreateExclusion', summary: 'Exclude a customer or an item from dunning', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Exclusion')])]
    public function store(ExclusionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $exclusion = $this->exclusions->exclude($data['customer_id'], $data['receivable_item_id'] ?? null, $data['type'], $data['reason'], $data['notes'] ?? null, $this->userId($request));

        return $this->successResponse(new ExclusionResource($exclusion->load('customer')), 'Exclusion created', 201);
    }

    #[OA\Post(path: '/api/v1/debt-collection/exclusions/{id}/release', operationId: 'debtCollectionReleaseExclusion', summary: 'Release an exclusion, with a note', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Exclusion')])]
    public function release(ReleaseExclusionRequest $request, string $id): JsonResponse
    {
        $exclusion = $this->exclusions->release(Exclusion::query()->findOrFail($id), $request->validated('note'), $this->userId($request));

        return $this->successResponse(new ExclusionResource($exclusion->load('customer')));
    }

    #[OA\Get(path: '/api/v1/debt-collection/payment-plans', operationId: 'debtCollectionListPlans', summary: 'List payment plans', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Plans')])]
    public function plans(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);
        $customer = $request->query('customer_id') === null ? null : (string) $request->query('customer_id');

        return $this->successResponse(PaymentPlanResource::collection($this->listing->plans($customer)));
    }

    #[OA\Post(path: '/api/v1/debt-collection/payment-plans', operationId: 'debtCollectionCreatePlan', summary: 'Record a payment plan; dunning is suspended while it is active', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Plan')])]
    public function storePlan(PaymentPlanRequest $request): JsonResponse
    {
        $plan = $this->exclusions->createPlan($request->validated(), $this->userId($request));

        return $this->successResponse(new PaymentPlanResource($plan->load('customer')), 'Plan created', 201);
    }

    #[OA\Post(path: '/api/v1/debt-collection/payment-plans/{id}/{transition}', operationId: 'debtCollectionTransitionPlan', summary: 'Complete, default or cancel a payment plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'transition', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['complete', 'default', 'cancel']))], responses: [new OA\Response(response: 200, description: 'Plan')])]
    public function transition(Request $request, string $id, string $transition): JsonResponse
    {
        Gate::authorize('manage', Notice::class);
        $status = ['complete' => 'completed', 'default' => 'defaulted', 'cancel' => 'cancelled'][$transition] ?? abort(404);

        return $this->successResponse(new PaymentPlanResource($this->exclusions->transitionPlan(PaymentPlan::query()->findOrFail($id), $status, $this->userId($request))->load('customer')));
    }
}
