<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\SalesBoundedContext\Application\Queries\GetOmnichannelPipelineOverviewHandler;
use Modules\SalesBoundedContext\Application\Queries\GetOmnichannelPipelineOverviewQuery;
use Modules\SalesBoundedContext\Domain\Enums\CommissionAccrualBasis;
use Modules\SalesBoundedContext\Domain\Enums\CustomerHierarchyRole;
use Modules\SalesBoundedContext\Domain\Enums\ElectronicSignatureStandard;
use Modules\SalesBoundedContext\Domain\Enums\ForecastCategory;
use Modules\SalesBoundedContext\Domain\Enums\InsolvencyRiskStatus;
use Modules\SalesBoundedContext\Domain\Enums\LeadLifecycleStage;
use Modules\SalesBoundedContext\Domain\Enums\PortalPaymentProvider;
use Modules\SalesBoundedContext\Domain\Enums\SubscriptionLifecycleAction;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use OpenApi\Attributes as OA;

/**
 * Serves seeded 5-locale UI labels, workflow steps, lookup options, and CH/IT domain presets
 * for Mod 033-042 so the Angular frontend requires zero hardcoded i18n keys.
 */
final class SalesBoundedContextConfigController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly GetOmnichannelPipelineOverviewHandler $overviewHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/config',
        operationId: 'getSalesBoundedContextConfig',
        summary: 'Get seeded 5-locale UI copy, workflow steps, and CH/IT presets for Mod 033-042',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Seeded SalesBoundedContext configuration')]
    )]
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', SbcCustomerDossier::class);

        $ui = __('sales_bounded_context.ui');
        $statuses = __('sales_bounded_context.statuses');
        $presets = __('sales_bounded_context.presets');

        return response()->json([
            'data' => [
                'ui_copy' => is_array($ui) ? $ui : [],
                'presets' => is_array($presets) ? $presets : [],
                'hierarchy_roles' => $this->mapOptions(CustomerHierarchyRole::values(), is_array($statuses) ? $statuses : []),
                'insolvency_statuses' => $this->mapOptions(InsolvencyRiskStatus::values(), is_array($statuses) ? $statuses : []),
                'lead_stages' => $this->mapOptions(LeadLifecycleStage::values(), is_array($statuses) ? $statuses : []),
                'forecast_categories' => $this->mapOptions(ForecastCategory::values(), is_array($statuses) ? $statuses : []),
                'signature_standards' => $this->mapOptions(ElectronicSignatureStandard::values(), is_array($statuses) ? $statuses : []),
                'subscription_actions' => $this->mapOptions(SubscriptionLifecycleAction::values(), is_array($statuses) ? $statuses : []),
                'commission_bases' => $this->mapOptions(CommissionAccrualBasis::values(), is_array($statuses) ? $statuses : []),
                'payment_providers' => $this->mapOptions(PortalPaymentProvider::values(), is_array($statuses) ? $statuses : []),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/overview',
        operationId: 'getSalesBoundedContextOverview',
        summary: 'Get aggregated omnichannel pipeline KPIs across Mod 033-042',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Omnichannel pipeline overview')]
    )]
    public function overview(): JsonResponse
    {
        $this->authorize('viewAny', SbcCustomerDossier::class);

        return response()->json([
            'data' => $this->overviewHandler->handle(new GetOmnichannelPipelineOverviewQuery($this->tenantId())),
        ]);
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $labels
     * @return list<array{code: string, label: string}>
     */
    private function mapOptions(array $values, array $labels): array
    {
        return array_map(static fn (string $code): array => [
            'code' => $code,
            'label' => $labels[$code] ?? $code,
        ], $values);
    }
}
