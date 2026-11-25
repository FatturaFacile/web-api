<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CorporateConventions\Application\Commands\ApplyConventionAndSplitBillingOnCheckoutCommand;
use Modules\CorporateConventions\Application\Commands\ApplyConventionAndSplitBillingOnCheckoutHandler;
use Modules\CorporateConventions\Application\Queries\CalculateConventionCartPricingHandler;
use Modules\CorporateConventions\Application\Queries\CalculateConventionCartPricingQuery;
use Modules\CorporateConventions\Domain\Enums\SourceDocumentType;
use Modules\CorporateConventions\Http\Controllers\Concerns\InteractsWithConventions;
use Modules\CorporateConventions\Http\Requests\CartRequest;
use Modules\CorporateConventions\Http\Resources\EnrollmentResource;
use Modules\CorporateConventions\Http\Resources\SplitBillingResource;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use OpenApi\Attributes as OA;

/**
 * Till and checkout: pricing of a cart under a convention pass and the atomic commit of the discount and the split billing.
 */
final class PosController extends Controller
{
    use ApiResponse;
    use InteractsWithConventions;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/pos/pricing', operationId: 'corporateConventionsPosPricing', summary: 'Price a cart under a convention pass: discounts, remaining cap and the employer / employee split', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Pricing of the cart')])]
    public function pricing(CartRequest $request, CalculateConventionCartPricingHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ConventionBeneficiaryEnrollment::class);
        $data = $request->validated();
        $result = $handler->handle(new CalculateConventionCartPricingQuery((string) $data['identifier'], $data['convention_id'] ?? null, $data['lines'], $data['currency'] ?? null));

        return $this->successResponse([
            'enrollment' => new EnrollmentResource($result['enrollment']->load('convention')),
            ...$result['pricing']->toArray(),
        ], $this->message('priced'));
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/pos/checkout', operationId: 'corporateConventionsPosCheckout', summary: 'Commit the convention discount and employer subsidy of a purchase and record its split billing', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 201, description: 'Split billing recorded')])]
    public function checkout(CartRequest $request, ApplyConventionAndSplitBillingOnCheckoutHandler $handler): JsonResponse
    {
        Gate::authorize('create', ConventionSplitBilling::class);
        $data = $request->validated();
        $result = $handler->handle(new ApplyConventionAndSplitBillingOnCheckoutCommand(
            (string) $data['enrollment_id'],
            $data['lines'],
            SourceDocumentType::from((string) $data['source_document_type']),
            (string) $data['source_document_id'],
            $data['employee_document_id'] ?? null,
            isset($data['transaction_date']) ? CarbonImmutable::parse((string) $data['transaction_date']) : null,
            $data['currency'] ?? null,
            $this->userId(),
        ));

        return $this->successResponse([
            'split_billing' => new SplitBillingResource($result['split_billing']),
            ...$result['pricing']->toArray(),
        ], $this->message('applied'), 201);
    }
}
