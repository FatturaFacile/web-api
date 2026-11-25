<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\DebtCollection\Http\Requests\InterestRateRequest;
use Modules\DebtCollection\Http\Requests\OfficeRequest;
use Modules\DebtCollection\Http\Requests\PolicyRequest;
use Modules\DebtCollection\Http\Requests\TemplateRequest;
use Modules\DebtCollection\Http\Resources\EnforcementOfficeResource;
use Modules\DebtCollection\Http\Resources\InterestRateResource;
use Modules\DebtCollection\Http\Resources\PolicyResource;
use Modules\DebtCollection\Http\Resources\TemplateResource;
use Modules\DebtCollection\Models\EnforcementOffice;
use Modules\DebtCollection\Models\InterestRate;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\Policy;
use Modules\DebtCollection\Models\Template;
use Modules\DebtCollection\Services\ConfigurationService;
use OpenApi\Attributes as OA;

/**
 * Configuration: dunning policies and their levels, the interest rate periods (Art. 104 CO, ECB + 8 points), the notice
 * texts and the Swiss enforcement offices.
 */
final class ConfigurationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConfigurationService $configuration) {}

    #[OA\Get(path: '/api/v1/debt-collection/policies', operationId: 'debtCollectionListPolicies', summary: 'List dunning policies with their levels', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Policies')])]
    public function policies(): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse(PolicyResource::collection($this->configuration->policies()));
    }

    #[OA\Post(path: '/api/v1/debt-collection/policies', operationId: 'debtCollectionCreatePolicy', summary: 'Create a dunning policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Policy')])]
    public function storePolicy(PolicyRequest $request): JsonResponse
    {
        return $this->successResponse(new PolicyResource($this->configuration->savePolicy(null, $request->validated())), 'Policy created', 201);
    }

    #[OA\Get(path: '/api/v1/debt-collection/policies/{id}', operationId: 'debtCollectionShowPolicy', summary: 'Show a dunning policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Policy')])]
    public function showPolicy(string $id): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse(new PolicyResource(Policy::query()->with('levels')->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/debt-collection/policies/{id}', operationId: 'debtCollectionUpdatePolicy', summary: 'Update a dunning policy and its levels', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Policy')])]
    public function updatePolicy(PolicyRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new PolicyResource($this->configuration->savePolicy(Policy::query()->findOrFail($id), $request->validated())));
    }

    #[OA\Delete(path: '/api/v1/debt-collection/policies/{id}', operationId: 'debtCollectionDeletePolicy', summary: 'Delete a dunning policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroyPolicy(string $id): JsonResponse
    {
        Gate::authorize('manage', Notice::class);
        $this->configuration->deletePolicy(Policy::query()->findOrFail($id));

        return $this->successResponse(null, 'Policy deleted');
    }

    #[OA\Get(path: '/api/v1/debt-collection/interest-rates', operationId: 'debtCollectionListInterestRates', summary: 'List the late-payment interest rate periods', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Rates')])]
    public function rates(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);
        $jurisdiction = in_array($request->query('jurisdiction'), ['CH', 'IT'], true) ? (string) $request->query('jurisdiction') : null;

        return $this->successResponse(InterestRateResource::collection($this->configuration->rates($jurisdiction)));
    }

    #[OA\Post(path: '/api/v1/debt-collection/interest-rates', operationId: 'debtCollectionCreateInterestRate', summary: 'Add an interest rate period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Rate')])]
    public function storeRate(InterestRateRequest $request): JsonResponse
    {
        return $this->successResponse(new InterestRateResource($this->configuration->saveRate(null, $request->validated())), 'Rate created', 201);
    }

    #[OA\Put(path: '/api/v1/debt-collection/interest-rates/{id}', operationId: 'debtCollectionUpdateInterestRate', summary: 'Update an interest rate period of the company', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Rate')])]
    public function updateRate(InterestRateRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new InterestRateResource($this->configuration->saveRate(InterestRate::query()->findOrFail($id), $request->validated())));
    }

    #[OA\Delete(path: '/api/v1/debt-collection/interest-rates/{id}', operationId: 'debtCollectionDeleteInterestRate', summary: 'Delete an interest rate period of the company', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroyRate(string $id): JsonResponse
    {
        Gate::authorize('manage', Notice::class);
        $this->configuration->deleteRate(InterestRate::query()->findOrFail($id));

        return $this->successResponse(null, 'Rate deleted');
    }

    #[OA\Get(path: '/api/v1/debt-collection/templates', operationId: 'debtCollectionListTemplates', summary: 'List the notice texts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Templates')])]
    public function templates(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse(TemplateResource::collection($this->configuration->templates($request->query('code') === null ? null : (string) $request->query('code'), $request->query('locale') === null ? null : (string) $request->query('locale'))));
    }

    #[OA\Put(path: '/api/v1/debt-collection/templates/{id}', operationId: 'debtCollectionUpdateTemplate', summary: 'Edit a notice text; a seeded text gets a company copy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Template')])]
    public function updateTemplate(TemplateRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new TemplateResource($this->configuration->saveTemplate(Template::query()->findOrFail($id), $request->validated())));
    }

    #[OA\Get(path: '/api/v1/debt-collection/enforcement-offices', operationId: 'debtCollectionListOffices', summary: 'List the Swiss enforcement offices', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Offices')])]
    public function offices(): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse(EnforcementOfficeResource::collection($this->configuration->offices()));
    }

    #[OA\Post(path: '/api/v1/debt-collection/enforcement-offices', operationId: 'debtCollectionCreateOffice', summary: 'Add an enforcement office', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Office')])]
    public function storeOffice(OfficeRequest $request): JsonResponse
    {
        return $this->successResponse(new EnforcementOfficeResource($this->configuration->saveOffice(null, $request->validated())), 'Office created', 201);
    }

    #[OA\Put(path: '/api/v1/debt-collection/enforcement-offices/{id}', operationId: 'debtCollectionUpdateOffice', summary: 'Update an enforcement office', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Office')])]
    public function updateOffice(OfficeRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new EnforcementOfficeResource($this->configuration->saveOffice(EnforcementOffice::query()->findOrFail($id), $request->validated())));
    }

    #[OA\Delete(path: '/api/v1/debt-collection/enforcement-offices/{id}', operationId: 'debtCollectionDeleteOffice', summary: 'Delete an enforcement office', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroyOffice(string $id): JsonResponse
    {
        Gate::authorize('manage', Notice::class);
        EnforcementOffice::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Office deleted');
    }
}
