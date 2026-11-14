<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\LeaseAccounting\Http\Controllers\Concerns\InteractsWithLeases;
use Modules\LeaseAccounting\Http\Requests\MaturityReportRequest;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Services\LeaseMaturityReportService;
use OpenApi\Attributes as OA;

/**
 * Disclosure reports of the leases.
 */
final class LeaseReportController extends Controller
{
    use InteractsWithLeases;

    public function __construct(private readonly LeaseMaturityReportService $reports) {}

    /**
     * Maturity analysis.
     */
    #[OA\Get(path: '/api/v1/leases/reports/maturity', operationId: 'leaseMaturityReport', description: 'Maturity analysis of the lease liabilities at as_of (default today) for the notes to the financial statements: undiscounted payments still to be made within one year, between one and five years and after five years, total undiscounted against discounted (the lease liability) with the unearned interest, the current and non-current liability and the carrying amount of the right-of-use assets, per lease and in total. One section per currency (amounts are never added across currencies). The commitments of leases with the short-term or low-value exemption are reported apart.', summary: 'Lease maturity analysis', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Leases'], parameters: [
        new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'currency', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['EUR', 'CHF'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Maturity analysis', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LeaseMaturityReport')])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function maturity(MaturityReportRequest $request): JsonResponse
    {
        $this->authorize('viewAny', LeaseContract::class);
        $asOf = $request->filled('as_of') ? CarbonImmutable::parse((string) $request->validated('as_of')) : CarbonImmutable::today();

        return response()->json(['data' => $this->reports->report($this->tenantId(), $asOf, $request->filled('currency') ? (string) $request->validated('currency') : null)]);
    }
}
