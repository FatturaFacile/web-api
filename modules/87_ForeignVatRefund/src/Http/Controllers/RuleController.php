<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\ForeignVatRefund\Http\Controllers\Concerns\InteractsWithForeignVat;
use Modules\ForeignVatRefund\Http\Requests\SaveRuleRequest;
use Modules\ForeignVatRefund\Http\Resources\RuleResource;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;
use Modules\ForeignVatRefund\Services\RefundRuleResolver;
use Modules\ForeignVatRefund\Services\RuleOverrideService;
use OpenApi\Attributes as OA;

/**
 * The reciprocity and deductibility matrix: the global rules and the overrides of the company.
 */
final class RuleController extends Controller
{
    use ApiResponse;
    use InteractsWithForeignVat;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/foreign-vat-refund/rules', operationId: 'foreignVatRefundRulesIndex', summary: 'The matrix of the home country of the company: one rule per country and category, company overrides first', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Rules')])]
    public function index(Request $request, RefundRuleResolver $rules): JsonResponse
    {
        Gate::authorize('viewAny', CountryVatRefundRule::class);
        $tenantId = $this->tenantId();
        $home = strtoupper((string) $request->query('home_country', $rules->homeCountry($tenantId)));
        $countries = $request->query('country') !== null ? [strtoupper((string) $request->query('country'))] : $rules->refundCountries($tenantId, $home);
        $matrix = [];

        foreach ($countries as $country) {
            foreach ($rules->matrix($tenantId, $home, $country) as $rule) {
                $matrix[] = $rule;
            }
        }

        return $this->successResponse(RuleResource::collection($matrix), $this->message('listed'), 200, ['home_country' => $home, 'countries' => $countries]);
    }

    #[OA\Post(path: '/api/v1/foreign-vat-refund/rules', operationId: 'foreignVatRefundRulesStore', summary: 'Customise one cell of the matrix with a company rule', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 201, description: 'Rule')])]
    public function store(SaveRuleRequest $request, RuleOverrideService $overrides): JsonResponse
    {
        Gate::authorize('create', CountryVatRefundRule::class);

        return $this->createdResponse(new RuleResource($overrides->save($this->tenantId(), $request->validated())), $this->message('rule_saved'));
    }

    #[OA\Put(path: '/api/v1/foreign-vat-refund/rules/{id}', operationId: 'foreignVatRefundRulesUpdate', summary: 'Change a company rule (global rules are read-only)', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Rule')])]
    public function update(SaveRuleRequest $request, string $id, RuleOverrideService $overrides): JsonResponse
    {
        Gate::authorize('create', CountryVatRefundRule::class);

        return $this->successResponse(new RuleResource($overrides->save($this->tenantId(), $request->validated(), $id)), $this->message('rule_saved'));
    }

    #[OA\Delete(path: '/api/v1/foreign-vat-refund/rules/{id}', operationId: 'foreignVatRefundRulesDestroy', summary: 'Remove a company rule: the global rule applies again', security: self::SECURITY, tags: ['Foreign VAT refund'], responses: [new OA\Response(response: 200, description: 'Reverted')])]
    public function destroy(string $id, RuleOverrideService $overrides): JsonResponse
    {
        Gate::authorize('create', CountryVatRefundRule::class);
        $overrides->revert($this->tenantId(), $id);

        return $this->successResponse(null, $this->message('rule_reverted'));
    }
}
