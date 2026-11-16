<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\CreditManagement\Http\Controllers\Concerns\InteractsWithCreditManagement;
use Modules\CreditManagement\Http\Requests\InsuranceCoverRequest;
use Modules\CreditManagement\Http\Requests\InsurancePolicyRequest;
use Modules\CreditManagement\Http\Resources\InsuranceCoverResource;
use Modules\CreditManagement\Http\Resources\InsurancePolicyResource;
use Modules\CreditManagement\Models\CreditInsuranceCover;
use Modules\CreditManagement\Models\CreditInsurancePolicy;
use Modules\CreditManagement\Services\InsuranceService;
use OpenApi\Attributes as OA;

/**
 * Credit insurance policies and the covers granted per customer.
 */
final class InsuranceController extends Controller
{
    use InteractsWithCreditManagement;

    public function __construct(private readonly InsuranceService $insurance) {}

    #[OA\Get(path: '/api/v1/credit-management/insurance-policies', operationId: 'listCreditInsurancePolicies', description: 'Policies, latest start first, with the total of the covers granted. Filter: status.', summary: 'List credit insurance policies', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List credit insurance policies', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditInsurancePolicy'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function policies(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditInsurancePolicy::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(CreditInsurancePolicy::Statuses)]]);

        return InsurancePolicyResource::collection(
            CreditInsurancePolicy::query()
                ->withSum(['covers as granted_total' => static fn ($query) => $query->where('status', CreditInsuranceCover::Granted)], 'insured_limit')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderByDesc('starts_on')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/credit-management/insurance-policies', operationId: 'storeCreditInsurancePolicy', description: 'Record a credit insurance policy.', summary: 'Create a credit insurance policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditInsurancePolicyRequest')), tags: ['Credit management'], responses: [
        new OA\Response(response: 201, description: 'Create a credit insurance policy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditInsurancePolicy')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function storePolicy(InsurancePolicyRequest $request): JsonResponse
    {
        $this->authorize('create', CreditInsurancePolicy::class);

        return (new InsurancePolicyResource($this->insurance->createPolicy($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/credit-management/insurance-policies/{id}', operationId: 'showCreditInsurancePolicy', description: 'Return a policy.', summary: 'Show a credit insurance policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Show a credit insurance policy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditInsurancePolicy')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function showPolicy(string $id): InsurancePolicyResource
    {
        $policy = CreditInsurancePolicy::query()
            ->withSum(['covers as granted_total' => static fn ($query) => $query->where('status', CreditInsuranceCover::Granted)], 'insured_limit')
            ->findOrFail($id);
        $this->authorize('view', $policy);

        return new InsurancePolicyResource($policy);
    }

    #[OA\Put(path: '/api/v1/credit-management/insurance-policies/{id}', operationId: 'updateCreditInsurancePolicy', description: 'Change a policy. The total cover cannot go below the covers already granted.', summary: 'Update a credit insurance policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditInsurancePolicyRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Update a credit insurance policy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditInsurancePolicy')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function updatePolicy(InsurancePolicyRequest $request, string $id): InsurancePolicyResource
    {
        $policy = CreditInsurancePolicy::query()->findOrFail($id);
        $this->authorize('update', $policy);

        return new InsurancePolicyResource($this->insurance->updatePolicy($policy, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/credit-management/insurance-policies/{id}', operationId: 'deleteCreditInsurancePolicy', description: 'Delete a policy without covers (soft delete); a policy with covers is cancelled instead.', summary: 'Delete a credit insurance policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 204, description: 'Delete a credit insurance policy'),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function destroyPolicy(string $id): Response
    {
        $policy = CreditInsurancePolicy::query()->findOrFail($id);
        $this->authorize('delete', $policy);
        $this->insurance->deletePolicy($policy);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/credit-management/insurance-covers', operationId: 'listCreditInsuranceCovers', description: 'Covers per customer. Filters: policy_id, customer_id, status.', summary: 'List insurance covers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], responses: [
        new OA\Response(response: 200, description: 'List insurance covers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditInsuranceCover'))])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
    ])]
    public function covers(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CreditInsuranceCover::class);
        $filters = $request->validate(['policy_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(CreditInsuranceCover::Statuses)]]);
        $page = CreditInsuranceCover::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['policy_id']), static fn ($query) => $query->where('policy_id', $filters['policy_id']))
            ->when(isset($filters['customer_id']), static fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($this->perPage());
        $this->withCustomerNames($page->getCollection());

        return InsuranceCoverResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/credit-management/insurance-policies/{id}/covers', operationId: 'storeCreditInsuranceCover', description: 'Add the cover of a customer under a policy in force. The granted covers stay within the total cover of the policy and a customer has one live cover per policy.', summary: 'Grant a cover on a customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditInsuranceCoverRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 201, description: 'Grant a cover on a customer', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditInsuranceCover')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function storeCover(InsuranceCoverRequest $request, string $id): JsonResponse
    {
        $policy = CreditInsurancePolicy::query()->findOrFail($id);
        $this->authorize('update', $policy);
        $cover = $this->insurance->createCover($this->tenantId(), $policy, $request->validated());
        $this->withCustomerNames([$cover]);

        return (new InsuranceCoverResource($cover))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/credit-management/insurance-covers/{id}', operationId: 'updateCreditInsuranceCover', description: 'Change the limit, status or dates of a cover (the customer cannot change).', summary: 'Update an insurance cover', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreditInsuranceCoverRequest')), tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 200, description: 'Update an insurance cover', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CreditInsuranceCover')])),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CreditUnprocessable', response: 422),
    ])]
    public function updateCover(InsuranceCoverRequest $request, string $id): InsuranceCoverResource
    {
        $cover = CreditInsuranceCover::query()->findOrFail($id);
        $this->authorize('update', $cover);
        $cover = $this->insurance->updateCover($cover, $request->validated());
        $this->withCustomerNames([$cover]);

        return new InsuranceCoverResource($cover);
    }

    #[OA\Delete(path: '/api/v1/credit-management/insurance-covers/{id}', operationId: 'deleteCreditInsuranceCover', description: 'Delete a cover (soft delete).', summary: 'Delete an insurance cover', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Credit management'], parameters: [new OA\Parameter(ref: '#/components/parameters/CreditId')], responses: [
        new OA\Response(response: 204, description: 'Delete an insurance cover'),
        new OA\Response(ref: '#/components/responses/CreditUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CreditForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CreditNotFound', response: 404),
    ])]
    public function destroyCover(string $id): Response
    {
        $cover = CreditInsuranceCover::query()->findOrFail($id);
        $this->authorize('delete', $cover);
        $cover->delete();

        return response()->noContent();
    }
}
