<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Support\ConnectorDefinition;
use Modules\Marketplace\Support\ConnectorRegistry;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The connector catalog: what can be installed, with its version, category, configuration schema and required scopes.
 */
final class CatalogController extends Controller
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    #[OA\Get(path: '/api/v1/marketplace/connectors', operationId: 'listMarketplaceConnectors', description: 'The connectors of the catalog. Filters: category, search (name, description). Each entry says how many installations the company already has.', summary: 'List the connector catalog', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], responses: [
        new OA\Response(response: 200, description: 'Catalog', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MarketplaceConnector'))])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceUnprocessable', response: 422),
    ])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceInstallation::class);
        $filters = $request->validate(['category' => ['nullable', Rule::in(ConnectorDefinition::Categories)], 'search' => ['nullable', 'string', 'max:100']]);
        $installed = MarketplaceInstallation::query()->selectRaw('connector_key, count(*) as total')->groupBy('connector_key')->pluck('total', 'connector_key');
        $search = mb_strtolower((string) ($filters['search'] ?? ''));

        $entries = array_values(array_filter(
            array_map(static fn (ConnectorDefinition $definition): array => [...$definition->toArray(), 'installations' => (int) ($installed[$definition->key] ?? 0)], $this->registry->definitions($filters['category'] ?? null)),
            static fn (array $entry): bool => $search === '' || str_contains(mb_strtolower($entry['name'].' '.$entry['description']), $search),
        ));

        return response()->json(['data' => $entries]);
    }

    #[OA\Get(path: '/api/v1/marketplace/connectors/{key}', operationId: 'showMarketplaceConnector', description: 'One connector of the catalog with its configuration schema (which fields are secrets) and required scopes.', summary: 'Show a connector', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketplace'], parameters: [new OA\Parameter(ref: '#/components/parameters/MarketplaceConnectorKey')], responses: [
        new OA\Response(response: 200, description: 'Connector', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MarketplaceConnector')])),
        new OA\Response(ref: '#/components/responses/MarketplaceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/MarketplaceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/MarketplaceNotFound', response: 404),
    ])]
    public function show(string $key): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceInstallation::class);
        $connector = $this->registry->find($key) ?? throw new NotFoundHttpException(__('marketplace_errors.installation.unknown_connector'));

        return response()->json(['data' => [...$connector->definition()->toArray(), 'installations' => MarketplaceInstallation::query()->where('connector_key', $key)->count()]]);
    }
}
