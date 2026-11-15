<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Marketplace\Http\Controllers\Concerns\InteractsWithMarketplace;
use Modules\Marketplace\Http\Requests\ConfigureRequest;
use Modules\Marketplace\Http\Requests\InstallRequest;
use Modules\Marketplace\Http\Requests\UpgradeRequest;
use Modules\Marketplace\Http\Resources\InstallationResource;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Services\InstallationService;
use OpenApi\Attributes as OA;

/**
 * Installations of catalog connectors and their lifecycle: install, configure, enable, disable, upgrade, uninstall.
 */
final class InstallationController extends Controller
{
    use InteractsWithMarketplace;

    public function __construct(private readonly InstallationService $installations) {}

    #[OA\Get(path: '/api/v1/marketplace/installations', operationId: 'listMarketplaceInstallations', description: 'Installations of the company. Filters: status, connector_key. Credentials are never returned.', summary: 'List installations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], responses: [
        new OA\Response(response: 200, description: 'Installations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceInstallation'))])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MarketplaceInstallation::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(MarketplaceInstallation::Statuses)], 'connector_key' => ['nullable', 'string', 'max:60']]);

        return InstallationResource::collection(
            MarketplaceInstallation::query()
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['connector_key']), static fn ($query) => $query->where('connector_key', $filters['connector_key']))
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/marketplace/installations', operationId: 'installMarketplaceConnector', description: 'Installs a connector of the catalog. The company grants it the scopes it requires (and optionally more), never more than the installing member can do; the installation starts as pending_configuration. At most five installations of one connector per company, with distinct names.', summary: 'Install a connector', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MarketplaceInstallRequest')), tags: ['Marketplace'], responses: [
        new OA\Response(response: 201, description: 'Connector installed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceInstallation')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function store(InstallRequest $request): JsonResponse
    {
        $this->authorize('create', MarketplaceInstallation::class);
        $data = $request->validated();
        $installation = $this->installations->install($this->tenantId(), $this->currentUser(), $data['connector_key'], $data['name'], array_values($data['granted_scopes']));

        return (new InstallationResource($installation))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/marketplace/installations/{id}', operationId: 'showMarketplaceInstallation', description: 'An installation with its status, settings, the keys of the credentials that are set (never their values), scopes, rate limit and health.', summary: 'Show an installation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 200, description: 'Installation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceInstallation')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
    ])]
    public function show(string $id): InstallationResource
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('view', $installation);

        return new InstallationResource($installation);
    }

    #[OA\Put(path: '/api/v1/marketplace/installations/{id}/configuration', operationId: 'configureMarketplaceInstallation', description: 'Saves the configuration: a value for each field of the connector schema. Only fields of the schema are accepted, required ones must be present, address fields must be public https URLs. A secret that is not sent again keeps its stored value. The first valid configuration makes a pending installation disabled; a reconfiguration resets the health to unknown. The optional rate_limit_per_minute lowers the limit of the connector for this installation and never exceeds its maximum.', summary: 'Configure an installation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MarketplaceConfigureRequest')), tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 200, description: 'Installation configured', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceInstallation')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function configure(ConfigureRequest $request, string $id): InstallationResource
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('update', $installation);
        $options = $request->has('rate_limit_per_minute') ? ['rate_limit_per_minute' => $request->integer('rate_limit_per_minute') ?: null] : [];

        return new InstallationResource($this->installations->configure($installation, (array) $request->validated('config'), $options));
    }

    #[OA\Post(path: '/api/v1/marketplace/installations/{id}/enable', operationId: 'enableMarketplaceInstallation', description: 'Enables a configured installation. It needs a configuration, every scope the connector requires and a passing health check (an unhealthy connector stays disabled and the reason is returned).', summary: 'Enable an installation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 200, description: 'Installation enabled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceInstallation')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function enable(string $id): InstallationResource
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('update', $installation);

        return new InstallationResource($this->installations->enable($installation));
    }

    #[OA\Post(path: '/api/v1/marketplace/installations/{id}/disable', operationId: 'disableMarketplaceInstallation', description: 'Disables an installation: it stops synchronising, configuration and history are kept.', summary: 'Disable an installation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 200, description: 'Installation disabled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceInstallation')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
    ])]
    public function disable(string $id): InstallationResource
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('update', $installation);

        return new InstallationResource($this->installations->disable($installation));
    }

    #[OA\Post(path: '/api/v1/marketplace/installations/{id}/upgrade', operationId: 'upgradeMarketplaceInstallation', description: 'Moves an installation to the newer version of its connector. Scopes the new version requires must be granted in granted_scopes; when the stored configuration no longer satisfies the new schema the installation returns to pending_configuration.', summary: 'Upgrade an installation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/MarketplaceUpgradeRequest')), tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 200, description: 'Installation upgraded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceInstallation')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function upgrade(UpgradeRequest $request, string $id): InstallationResource
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('update', $installation);

        return new InstallationResource($this->installations->upgrade($installation, $this->currentUser(), array_values((array) $request->validated('granted_scopes'))));
    }

    #[OA\Delete(path: '/api/v1/marketplace/installations/{id}', operationId: 'uninstallMarketplaceConnector', description: 'Uninstalls a connector: the credentials and settings are wiped, the history of the runs is kept. Refused while a synchronisation is in progress.', summary: 'Uninstall a connector', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceId')], responses: [
        new OA\Response(response: 204, description: 'Connector uninstalled'),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $installation = MarketplaceInstallation::query()->findOrFail($id);
        $this->authorize('delete', $installation);
        $this->installations->uninstall($installation);

        return response()->noContent();
    }
}
