<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Beekeeping\Domain\Enums\Country;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\QuoteTaxRequest;
use Modules\Beekeeping\Http\Requests\SaveTaxProfileRequest;
use Modules\Beekeeping\Models\TaxProfile;
use Modules\Beekeeping\Services\AgriculturalTaxService;
use OpenApi\Attributes as OA;

/**
 * Agricultural tax profile (Art. 34 DPR 633/72, Swiss reduced rate) and the VAT quote of apiculture products.
 */
final class TaxController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly AgriculturalTaxService $tax) {}

    #[OA\Get(path: '/api/v1/beekeeping/tax/profile', operationId: 'beekeepingTaxProfile', summary: 'Agricultural tax profile of a country', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Profile')])]
    public function profile(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TaxProfile::class);
        $country = (string) $request->query('country', 'IT');

        return $this->successResponse($this->tax->profile(in_array($country, Country::values(), true) ? $country : 'IT'));
    }

    #[OA\Put(path: '/api/v1/beekeeping/tax/profile', operationId: 'beekeepingTaxProfileSave', summary: 'Save the agricultural tax profile', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Profile saved')])]
    public function saveProfile(SaveTaxProfileRequest $request): JsonResponse
    {
        $profile = $this->tax->saveProfile($request->validated());

        return $this->successResponse($this->tax->profile($profile->country), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/beekeeping/tax/quote', operationId: 'beekeepingTaxQuote', summary: 'VAT of a sale of apiculture products', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Quote')])]
    public function quote(QuoteTaxRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($this->tax->quote((string) $data['country'], (string) $data['product_kind'], (float) $data['net_amount'], (string) ($data['sale_channel'] ?? 'direct')));
    }
}
