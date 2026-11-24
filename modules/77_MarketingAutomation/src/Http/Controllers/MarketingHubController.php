<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\MarketingAutomation\Application\Queries\GetMarketingRoiDashboardHandler;
use Modules\MarketingAutomation\Application\Queries\GetMarketingRoiDashboardQuery;
use Modules\MarketingAutomation\Application\Queries\SearchCatalogProductsHandler;
use Modules\MarketingAutomation\Http\Controllers\Concerns\ResolvesMarketingContext;
use Modules\MarketingAutomation\Http\Resources\BlueprintResource;
use Modules\MarketingAutomation\Models\MarketingBlueprint;
use Modules\MarketingAutomation\Services\Privacy\PrivacyStrategyResolver;
use OpenApi\Attributes as OA;

/**
 * Hub endpoints: ROI dashboard, seeded blueprints (segment presets, e-mail templates, journeys, texts), the ERP catalog
 * lookup of the designer and the compliance strategy overview.
 */
final class MarketingHubController extends Controller
{
    use ApiResponse;
    use ResolvesMarketingContext;

    #[OA\Get(path: '/api/v1/marketing-automation/dashboard', operationId: 'marketingRoiDashboard', summary: 'Marketing ROI dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'KPIs per campaign and in total')])]
    public function dashboard(Request $request, GetMarketingRoiDashboardHandler $handler): JsonResponse
    {
        $currency = $request->query('currency');
        $campaign = $request->query('campaign_id');

        return $this->successResponse($handler->handle(new GetMarketingRoiDashboardQuery(
            $this->tenantId(),
            is_string($currency) && strlen($currency) === 3 ? $currency : null,
            is_string($campaign) && $campaign !== '' ? $campaign : null,
        )));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/blueprints', operationId: 'marketingListBlueprints', summary: 'Seeded presets, templates, journeys and texts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Blueprints')])]
    public function blueprints(Request $request): JsonResponse
    {
        $kind = $request->query('kind');

        $rows = MarketingBlueprint::query()->where('is_active', true)
            ->when(is_string($kind) && $kind !== '', static fn ($q) => $q->where('kind', $kind))
            ->orderBy('kind')->orderBy('code')->get();

        return $this->successResponse(BlueprintResource::collection($rows));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/catalog/products', operationId: 'marketingCatalogProducts', summary: 'Search ERP products for the e-mail designer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Products with image, description and price')])]
    public function catalogProducts(Request $request, SearchCatalogProductsHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle($this->tenantId(), (string) $request->query('q', ''), (int) $request->query('limit', 20)));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/compliance/strategies', operationId: 'marketingComplianceStrategies', summary: 'Privacy compliance strategies (CH LCSl/nLPD, IT GDPR Art. 130)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Strategies')])]
    public function strategies(PrivacyStrategyResolver $resolver): JsonResponse
    {
        return $this->successResponse(array_map(static fn ($strategy): array => [
            'code' => $strategy->code(),
            'jurisdictions' => $strategy->supports('CH') ? ['CH'] : ['IT', 'EU'],
            'footer_keys' => $strategy->requiredFooterKeys(),
        ], $resolver->all()));
    }
}
