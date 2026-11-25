<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PriceList;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CorporateConventions\Domain\Enums\BillingCycle;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Domain\Enums\EnrollmentStatus;
use Modules\CorporateConventions\Domain\Enums\PartnerType;
use Modules\CorporateConventions\Domain\Enums\ReimbursementStatus;
use Modules\CorporateConventions\Domain\Enums\Relationship;
use Modules\CorporateConventions\Domain\Enums\RuleType;
use Modules\CorporateConventions\Domain\Enums\SplitBillingStatus;
use Modules\CorporateConventions\Domain\Enums\SubsidyPurpose;
use Modules\CorporateConventions\Domain\Enums\SubsidyType;
use Modules\CorporateConventions\Domain\Enums\VerificationMethod;
use Modules\CorporateConventions\Domain\Enums\WelfareCategory;
use Modules\CorporateConventions\Domain\Enums\WelfareJurisdiction;
use Modules\CorporateConventions\Domain\Enums\WelfareProvider;
use Modules\CorporateConventions\Domain\Support\WelfareVoucherPolicy;
use Modules\CorporateConventions\Http\Controllers\Concerns\InteractsWithConventions;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use OpenApi\Attributes as OA;

/**
 * Reference data of the conventions screens: the codes the API reports, the price lists and categories of the discount matrix,
 * the welfare providers allowed in each jurisdiction and the conventions open for selection at the till.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithConventions;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/lookups', operationId: 'corporateConventionsLookups', summary: 'Codes, price lists, categories, welfare providers and the conventions open at the till', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(WelfareVoucherPolicy $welfare): JsonResponse
    {
        Gate::authorize('viewAny', CorporatePartnerConvention::class);
        $providers = [];

        foreach (WelfareJurisdiction::cases() as $jurisdiction) {
            $providers[$jurisdiction->value] = array_values(array_map(static fn (WelfareProvider $provider): array => [
                'provider' => $provider->value,
                'categories' => array_map(static fn (WelfareCategory $category): string => $category->value, $welfare->categoriesOf($jurisdiction, $provider)),
                'default_fee_percentage' => (string) config('corporate_conventions.welfare_fees.'.$provider->value, '0'),
            ], array_filter(WelfareProvider::cases(), static function (WelfareProvider $provider) use ($jurisdiction): bool {
                $swiss = in_array($provider, [WelfareProvider::RekaCard, WelfareProvider::LunchCheck], true);

                return $provider === WelfareProvider::Other || ($jurisdiction === WelfareJurisdiction::ChRekaLunchcheck ? $swiss : ! $swiss);
            })));
        }

        return $this->successResponse([
            'partner_types' => PartnerType::values(),
            'convention_statuses' => ConventionStatus::values(),
            'verification_methods' => VerificationMethod::values(),
            'rule_types' => RuleType::values(),
            'subsidy_types' => SubsidyType::values(),
            'subsidy_purposes' => SubsidyPurpose::values(),
            'billing_cycles' => BillingCycle::values(),
            'relationships' => [Relationship::Spouse->value, Relationship::Child->value, Relationship::Parent->value],
            'enrollment_statuses' => EnrollmentStatus::values(),
            'split_billing_statuses' => SplitBillingStatus::values(),
            'reimbursement_statuses' => ReimbursementStatus::values(),
            'welfare_jurisdictions' => WelfareJurisdiction::values(),
            'welfare_categories' => WelfareCategory::values(),
            'welfare_providers' => $providers,
            'price_lists' => PriceList::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'currency'])->all(),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name', 'parent_id'])->all(),
            'conventions' => CorporatePartnerConvention::query()->where('status', ConventionStatus::Active->value)->orderBy('name')->get(['id', 'name', 'code', 'currency', 'country_code'])->all(),
        ], $this->message('loaded'));
    }
}
