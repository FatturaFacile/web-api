<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\LeaseAccounting\Http\Controllers\Concerns\InteractsWithLeases;
use Modules\LeaseAccounting\Http\Requests\PostLeasePeriodsRequest;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Services\LeasePostingService;
use OpenApi\Attributes as OA;

/**
 * Period postings of the leases (interest, depreciation and payment journals through the general ledger).
 */
final class LeasePostingController extends Controller
{
    use InteractsWithLeases;

    public function __construct(private readonly LeasePostingService $postings) {}

    /**
     * Post the periods of a lease.
     */
    #[OA\Post(path: '/api/v1/leases/contracts/{id}/post-periods', operationId: 'postLeasePeriods', description: 'Posts, in order, every period of an active lease that ended on or before through_date (not after today) and was not posted: one journal per period with the interest on the liability, the depreciation of the right-of-use asset and the payment due against the lessor payable. Idempotent: a period is posted once, repeating the call posts nothing. The last period closes the lease (expired).', summary: 'Post lease periods', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LeasePostPeriodsRequest')), tags: ['Leases'], parameters: [new OA\Parameter(ref: '#/components/parameters/LeasesId')], responses: [
        new OA\Response(response: 200, description: 'Periods posted by this call', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeasePeriodPosting'))])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function postContract(PostLeasePeriodsRequest $request, string $id): JsonResponse
    {
        $contract = $this->findContract($id);
        $this->authorize('post', $contract);
        $postings = $this->postings->postThrough($contract, CarbonImmutable::parse((string) $request->validated('through_date')), $this->userId());

        return response()->json(['data' => array_map(static fn ($posting): array => [
            'id' => (string) $posting->id,
            'lease_contract_id' => (string) $posting->lease_contract_id,
            'period_number' => $posting->period_number,
            'posting_date' => $posting->posting_date->toDateString(),
            'journal_entry_id' => (string) $posting->journal_entry_id,
            'interest' => $posting->interest,
            'depreciation' => $posting->depreciation,
            'payment' => $posting->payment,
        ], $postings)]);
    }

    /**
     * Post the periods of every lease.
     */
    #[OA\Post(path: '/api/v1/leases/postings/run', operationId: 'runLeasePostings', description: 'Period posting of every active lease of the company up to through_date (not after today). A lease the ledger refuses (for example a closed fiscal period) is reported with its error and does not stop the others.', summary: 'Post the periods of every lease', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LeasePostPeriodsRequest')), tags: ['Leases'], responses: [
        new OA\Response(response: 200, description: 'Result per lease', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeasePostingRunResult'))])),
        new OA\Response(ref: '#/components/responses/LeasesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/LeasesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/LeasesUnprocessable', response: 422),
    ])]
    public function run(PostLeasePeriodsRequest $request): JsonResponse
    {
        $this->authorize('postAny', LeaseContract::class);

        return response()->json(['data' => $this->postings->runForTenant($this->tenantId(), CarbonImmutable::parse((string) $request->validated('through_date')), $this->userId())]);
    }
}
