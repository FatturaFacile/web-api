<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Configurator\Http\Requests\ConfigurationDecisionRequest;
use Modules\Configurator\Http\Requests\ConfigurationQuoteRequest;
use Modules\Configurator\Http\Requests\ConfigurationRequest;
use Modules\Configurator\Http\Resources\ProductConfigurationResource;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ProductConfiguration;
use Modules\Configurator\Services\ConfigurationService;
use OpenApi\Attributes as OA;

/**
 * Saved configurations: validation, the margin guard with its discount approval, and the conversion into a quote.
 */
final class ConfigurationController extends Controller
{
    public function __construct(private readonly ConfigurationService $configurations) {}

    /**
     * List configurations.
     */
    #[OA\Get(path: '/api/v1/configurator/configurations', operationId: 'listConfiguratorConfigurations', description: 'Return the configurations of the company, newest first. Optional filters by status, model and customer.', summary: 'List configurations', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'validated', 'pending_approval', 'approved', 'rejected', 'converted'])),
        new OA\Parameter(name: 'model_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Configurations', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationCollection')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductConfiguration::class);

        $configurations = ProductConfiguration::query()
            ->with(['configurableModel', 'contact', 'quote'])
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('model_id'), static fn ($query) => $query->where('model_id', (string) $request->query('model_id')))
            ->when($request->filled('contact_id'), static fn ($query) => $query->where('contact_id', (string) $request->query('contact_id')))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return ProductConfigurationResource::collection($configurations);
    }

    /**
     * Create configuration.
     */
    #[OA\Post(path: '/api/v1/configurator/configurations', operationId: 'storeConfiguratorConfiguration', description: 'Save a configuration of a published model as a draft: the selections are completed with the defaults, evaluated and priced; violations are reported by the validate action, not here.', summary: 'Create configuration', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationRequest')), tags: ['Configurator'], responses: [
        new OA\Response(response: 201, description: 'Configuration created', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function store(ConfigurationRequest $request): JsonResponse
    {
        $this->authorize('create', ProductConfiguration::class);
        $model = ConfigurableModel::query()->findOrFail((string) $request->validated('model_id'));
        $configuration = $this->configurations->create($model, $request->payload(), $request->user());

        return (new ProductConfigurationResource($this->loaded($configuration)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Show configuration.
     */
    #[OA\Get(path: '/api/v1/configurator/configurations/{id}', operationId: 'showConfiguratorConfiguration', description: 'Return a configuration with its selections, price, margin, approval state and, once converted, the snapshot of the model version, rules and prices behind its quote.', summary: 'Show configuration', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Configuration', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
    ])]
    public function show(string $id): ProductConfigurationResource
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('view', $configuration);

        return new ProductConfigurationResource($this->loaded($configuration));
    }

    /**
     * Update configuration.
     */
    #[OA\Put(path: '/api/v1/configurator/configurations/{id}', operationId: 'updateConfiguratorConfiguration', description: 'Change the selections or the commercial terms: the configuration is evaluated and priced again and goes back to draft, losing any validation or approval. A configuration that waits for approval or became a quote cannot be changed.', summary: 'Update configuration', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Configuration updated', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function update(ConfigurationRequest $request, string $id): ProductConfigurationResource
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('update', $configuration);

        return new ProductConfigurationResource($this->loaded($this->configurations->update($configuration, $request->payload())));
    }

    /**
     * Delete configuration.
     */
    #[OA\Delete(path: '/api/v1/configurator/configurations/{id}', operationId: 'deleteConfiguratorConfiguration', description: 'Delete a draft or rejected configuration.', summary: 'Delete configuration', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 204, description: 'Configuration deleted'),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('delete', $configuration);
        $this->configurations->delete($configuration);

        return response()->noContent();
    }

    /**
     * Validate configuration.
     */
    #[OA\Post(path: '/api/v1/configurator/configurations/{id}/validate', operationId: 'validateConfiguratorConfiguration', description: 'Evaluate the stored selections against the current model. A configuration without violations becomes validated; otherwise it stays a draft. The violations are returned either way.', summary: 'Validate configuration', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Evaluation', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorEvaluationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function validateConfiguration(string $id): JsonResponse
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('update', $configuration);

        return response()->json(['data' => $this->configurations->validate($configuration)]);
    }

    /**
     * Request approval.
     */
    #[OA\Post(path: '/api/v1/configurator/configurations/{id}/request-approval', operationId: 'requestConfiguratorApproval', description: 'Ask for approval of a validated configuration whose margin is below the minimum of its model (margin guard).', summary: 'Request discount approval', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Approval requested', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function requestApproval(Request $request, string $id): ProductConfigurationResource
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('update', $configuration);

        return new ProductConfigurationResource($this->loaded($this->configurations->requestApproval($configuration, $request->user())));
    }

    /**
     * Approve configuration.
     */
    #[OA\Post(path: '/api/v1/configurator/configurations/{id}/approve', operationId: 'approveConfiguratorConfiguration', description: 'Approve the discount of a configuration that waits for approval. Owners, administrators, sales managers and members granted configurator.approve only.', summary: 'Approve discount', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorDecisionRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Configuration approved', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function approve(ConfigurationDecisionRequest $request, string $id): ProductConfigurationResource
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('approve', $configuration);

        return new ProductConfigurationResource($this->loaded($this->configurations->decide($configuration, $request->user(), true, $request->validated('decision_notes'))));
    }

    /**
     * Reject configuration.
     */
    #[OA\Post(path: '/api/v1/configurator/configurations/{id}/reject', operationId: 'rejectConfiguratorConfiguration', description: 'Reject the discount of a configuration that waits for approval; the reason is required. The author can change the configuration and start again.', summary: 'Reject discount', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorDecisionRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Configuration rejected', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function reject(ConfigurationDecisionRequest $request, string $id): ProductConfigurationResource
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('approve', $configuration);

        return new ProductConfigurationResource($this->loaded($this->configurations->decide($configuration, $request->user(), false, $request->validated('decision_notes'))));
    }

    /**
     * Convert to quote.
     */
    #[OA\Post(path: '/api/v1/configurator/configurations/{id}/convert-to-quote', operationId: 'convertConfiguratorConfigurationToQuote', description: 'Turn a validated (or approved) configuration into a quote with one line per resolved component, a configuration summary and a snapshot of the model version, rules and prices used, so that a later price change never alters the quote. Needs a customer. Refused while the margin is below the minimum and the discount is not approved, and when prices moved since validation.', summary: 'Convert configuration to quote', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorQuoteRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Configuration converted', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function convertToQuote(ConfigurationQuoteRequest $request, string $id): ProductConfigurationResource
    {
        $configuration = ProductConfiguration::query()->findOrFail($id);
        $this->authorize('update', $configuration);
        $this->authorize('create', Quote::class);

        return new ProductConfigurationResource($this->loaded($this->configurations->convertToQuote($configuration, $request->user(), $request->validated())));
    }

    private function loaded(ProductConfiguration $configuration): ProductConfiguration
    {
        return $configuration->load(['configurableModel', 'contact', 'quote']);
    }
}
