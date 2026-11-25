<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Domain\Enums\AgreementStatus;
use Modules\FranchiseNetwork\Domain\Enums\CollectionScheme;
use Modules\FranchiseNetwork\Domain\Enums\GuaranteeType;
use Modules\FranchiseNetwork\Domain\Enums\Jurisdiction;
use Modules\FranchiseNetwork\Domain\Enums\LoyaltyType;
use Modules\FranchiseNetwork\Domain\Enums\PaymentMethod;
use Modules\FranchiseNetwork\Domain\Enums\PriceType;
use Modules\FranchiseNetwork\Domain\Enums\PushType;
use Modules\FranchiseNetwork\Domain\Enums\SellOutSource;
use Modules\FranchiseNetwork\Domain\Enums\SizeCluster;
use Modules\FranchiseNetwork\Domain\Enums\StartupMode;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreType;
use Modules\FranchiseNetwork\Domain\Support\LocalizedText;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Resources\FormulaResource;
use Modules\FranchiseNetwork\Http\Resources\StoreResource;
use Modules\FranchiseNetwork\Http\Resources\TemplateResource;
use Modules\FranchiseNetwork\Models\AuditTemplate;
use Modules\FranchiseNetwork\Models\BenchmarkDimension;
use Modules\FranchiseNetwork\Models\FranchiseFormula;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Services\NetworkAccess;
use OpenApi\Attributes as OA;

/**
 * Code lists and seeded catalogues (formula presets, audit templates, benchmark dimensions) the screens of the module are built on.
 * The wording of the catalogues is seed data in the four languages; the labels of the code lists are in the frontend dictionary.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/franchise-network/lookups', operationId: 'franchiseNetworkLookups', summary: 'Code lists and seeded catalogues of the franchise network', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(NetworkAccess $access): JsonResponse
    {
        Gate::authorize('viewAny', FranchiseStore::class);
        $locale = app()->getLocale();

        return $this->successResponse([
            'codes' => [
                'store_types' => StoreType::values(),
                'store_statuses' => StoreStatus::values(),
                'size_clusters' => SizeCluster::values(),
                'agreement_statuses' => AgreementStatus::values(),
                'payment_methods' => PaymentMethod::values(),
                'guarantee_types' => GuaranteeType::values(),
                'startup_modes' => StartupMode::values(),
                'jurisdictions' => Jurisdiction::values(),
                'push_types' => PushType::values(),
                'price_types' => PriceType::values(),
                'sell_out_sources' => SellOutSource::values(),
                'loyalty_types' => LoyaltyType::values(),
                'collection_schemes' => CollectionScheme::values(),
                'audit_categories' => ['signage', 'cleanliness', 'visual_merchandising', 'layout', 'stock_pricing'],
            ],
            'formulas' => FormulaResource::collection(FranchiseFormula::query()->where('is_active', true)->orderByDesc('is_preset')->orderBy('code')->get()),
            'audit_templates' => TemplateResource::collection(AuditTemplate::query()->where('is_active', true)->orderBy('code')->get()),
            'benchmark_dimensions' => BenchmarkDimension::query()->orderBy('sort_order')->get()->map(static fn (BenchmarkDimension $dimension): array => [
                'code' => $dimension->code,
                'label' => LocalizedText::pick($dimension->labels, $locale),
                'description' => LocalizedText::pick($dimension->descriptions, $locale),
                'unit' => $dimension->unit,
                'higher_is_better' => $dimension->higher_is_better,
            ])->all(),
            'stores' => StoreResource::collection($this->visibleStores(FranchiseStore::query())->orderBy('code')->get()),
            'role' => [
                'is_franchisor' => $access->isFranchisor(),
                'is_franchisee' => $access->portalStores()->isNotEmpty(),
                'can_manage' => Gate::allows('manage', FranchiseStore::class),
                'can_audit' => Gate::allows('audit', FranchiseStore::class),
                'can_portal_write' => Gate::allows('portalWrite', FranchiseStore::class),
            ],
        ], $this->message('loaded'));
    }
}
