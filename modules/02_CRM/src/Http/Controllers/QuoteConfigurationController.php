<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\DecisionReasonRequest;
use Modules\CRM\Http\Requests\SaveQuoteConfigurationRequest;
use Modules\CRM\Models\QuoteConfiguration;
use Modules\CRM\Services\QuoteConfigurationService;
use OpenApi\Attributes as OA;

/**
 * CPQ quote configurations and discount approval.
 */
final class QuoteConfigurationController extends CrmExtensionController
{
    public function __construct(private readonly QuoteConfigurationService $quotes) {}

    #[OA\Get(path: '/api/v1/crm/quote-configurations', operationId: 'listCrmQuoteConfigurations', description: 'CPQ quote configurations, latest first. Filters: status, opportunity_id.', summary: 'List quote configurations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmQuoteConfiguration'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', 'string'], 'opportunity_id' => ['nullable', 'uuid']]);

        return $this->paginated($this->scoped(QuoteConfiguration::class)
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['opportunity_id']), static fn ($query) => $query->where('opportunity_id', $filters['opportunity_id']))
            ->orderByDesc('created_at'));
    }

    #[OA\Post(path: '/api/v1/crm/quote-configurations', operationId: 'createCrmQuoteConfiguration', description: 'Prices the quantity: the best pricing rule applies, the extra discount is added, and the unit price comes from the product configuration (module 46) when product_configuration_id is given. The discount and the approver role are computed by the API.', summary: 'Create a quote configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmQuoteConfigurationRequest')), tags: ['CRM CPQ'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmQuoteConfiguration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SaveQuoteConfigurationRequest $request): JsonResponse
    {
        $this->authorize('create', QuoteConfiguration::class);

        return $this->present($this->quotes->create($this->tenantId(), $request->payload()), 201);
    }

    #[OA\Post(path: '/api/v1/crm/quote-configurations/preview', operationId: 'previewCrmQuoteConfiguration', description: 'Computes the price, the discount and the required approver role without saving anything.', summary: 'Preview a quote price', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmQuoteConfigurationRequest')), tags: ['CRM CPQ'], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function preview(SaveQuoteConfigurationRequest $request): JsonResponse
    {
        $this->authorize('create', QuoteConfiguration::class);

        return response()->json(['data' => $this->quotes->preview($this->tenantId(), $request->payload())]);
    }

    #[OA\Get(path: '/api/v1/crm/quote-configurations/{id}', operationId: 'getCrmQuoteConfiguration', description: 'Return a quote configuration.', summary: 'Show a quote configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmQuoteConfiguration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(QuoteConfiguration::class, $id));
    }

    #[OA\Put(path: '/api/v1/crm/quote-configurations/{id}', operationId: 'updateCrmQuoteConfiguration', description: 'Only a draft or rejected quote can change; it is priced again and goes back to draft.', summary: 'Update a quote configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmQuoteConfigurationRequest')), tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmQuoteConfiguration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SaveQuoteConfigurationRequest $request, string $id): JsonResponse
    {
        $quote = $this->find(QuoteConfiguration::class, $id, 'update');

        return $this->present($this->quotes->update($quote, $request->payload()));
    }

    #[OA\Delete(path: '/api/v1/crm/quote-configurations/{id}', operationId: 'deleteCrmQuoteConfiguration', description: 'Delete a draft or rejected quote.', summary: 'Delete a quote configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $quote = $this->find(QuoteConfiguration::class, $id, 'delete');
        abort_unless(in_array($quote->status, ['draft', 'rejected'], true), 422, 'Only a draft or rejected quote can be deleted.');

        return $this->deleteRecord($quote);
    }

    #[OA\Post(path: '/api/v1/crm/quote-configurations/{id}/submit', operationId: 'submitCrmQuoteConfiguration', description: 'Prices the quote again and asks the approver role of the discount thresholds; a quote under every threshold is approved at once.', summary: 'Submit a quote for approval', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmQuoteConfiguration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function submit(string $id): JsonResponse
    {
        return $this->present($this->quotes->submit($this->find(QuoteConfiguration::class, $id, 'update')));
    }

    #[OA\Post(path: '/api/v1/crm/quote-configurations/{id}/approve', operationId: 'approveCrmQuoteConfiguration', description: 'Approves a quote pending approval; the user role must be the required approver role or higher (sales_manager, admin, owner).', summary: 'Approve a quote discount', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmQuoteConfiguration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function approve(string $id): JsonResponse
    {
        $quote = $this->find(QuoteConfiguration::class, $id, 'approve');

        return $this->present($this->quotes->approve($quote, $this->userId(), $this->userRole()));
    }

    #[OA\Post(path: '/api/v1/crm/quote-configurations/{id}/reject', operationId: 'rejectCrmQuoteConfiguration', description: 'Rejects a quote pending approval with a reason; the sales person can edit and submit it again.', summary: 'Reject a quote discount', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmDecisionReason')), tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmQuoteConfiguration')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function reject(DecisionReasonRequest $request, string $id): JsonResponse
    {
        $quote = $this->find(QuoteConfiguration::class, $id, 'approve');

        return $this->present($this->quotes->reject($quote, $this->userId(), $this->userRole(), (string) $request->validated('reason')));
    }
}
