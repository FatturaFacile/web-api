<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\SaveMdfFundRequest;
use Modules\CRM\Http\Requests\SubmitMdfClaimRequest;
use Modules\CRM\Http\Resources\CrmRecordResource;
use Modules\CRM\Models\MdfClaim;
use Modules\CRM\Models\MdfFund;
use Modules\CRM\Models\Partner;
use Modules\CRM\Services\MdfService;
use OpenApi\Attributes as OA;

/**
 * Market development funds of partners and their claims.
 */
final class MdfFundController extends CrmExtensionController
{
    public function __construct(private readonly MdfService $mdf) {}

    #[OA\Get(path: '/api/v1/crm/mdf-funds', operationId: 'listCrmMdfFunds', description: 'MDF funds with allocated, committed, pending and remaining amounts. Filters: partner_id, fiscal_year.', summary: 'List MDF funds', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmMdfFund'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['partner_id' => ['nullable', 'uuid'], 'fiscal_year' => ['nullable', 'integer']]);
        $this->authorize('viewAny', MdfFund::class);
        $page = $this->scoped(MdfFund::class)
            ->when(isset($filters['partner_id']), static fn ($query) => $query->where('partner_id', $filters['partner_id']))
            ->when(isset($filters['fiscal_year']), static fn ($query) => $query->where('fiscal_year', $filters['fiscal_year']))
            ->orderByDesc('fiscal_year')
            ->paginate($this->perPage());
        $page->getCollection()->each(fn (MdfFund $fund) => $fund->setAttribute('summary', $this->mdf->summary($fund)));

        return CrmRecordResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/crm/mdf-funds', operationId: 'createCrmMdfFund', description: 'One fund per partner and year. Without allocated_amount the fund accrues the MDF percentage of the partner tier on its closed revenue.', summary: 'Create an MDF fund', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmMdfFundRequest')), tags: ['CRM Partners'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmMdfFund')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SaveMdfFundRequest $request): JsonResponse
    {
        $this->authorize('create', MdfFund::class);
        $data = $request->validated();
        $partner = $this->find(Partner::class, (string) $data['partner_id'], 'view', ['tier']);
        $fund = $this->mdf->createFund($partner, (int) $data['fiscal_year'], isset($data['allocated_amount']) ? (string) $data['allocated_amount'] : null, (string) ($data['currency'] ?? 'EUR'));
        $fund->setAttribute('summary', $this->mdf->summary($fund));

        return $this->present($fund, 201);
    }

    #[OA\Get(path: '/api/v1/crm/mdf-funds/{id}', operationId: 'getCrmMdfFund', description: 'Return the fund with its summary.', summary: 'Show an MDF fund', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmMdfFund')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $fund = $this->find(MdfFund::class, $id);
        $fund->setAttribute('summary', $this->mdf->summary($fund));

        return $this->present($fund);
    }

    #[OA\Delete(path: '/api/v1/crm/mdf-funds/{id}', operationId: 'deleteCrmMdfFund', description: 'Delete a fund that has no approved or paid claim.', summary: 'Delete an MDF fund', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $fund = $this->find(MdfFund::class, $id, 'delete');
        abort_if($this->mdf->summary($fund)['committed'] !== '0.00', 422, 'The fund has approved or paid claims.');

        return $this->deleteRecord($fund);
    }

    #[OA\Get(path: '/api/v1/crm/mdf-funds/{id}/claims', operationId: 'listCrmMdfFundClaims', description: 'The claims against the fund.', summary: 'List claims of a fund', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmMdfClaim'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function claims(string $id): AnonymousResourceCollection
    {
        $this->find(MdfFund::class, $id);

        return $this->paginated($this->scoped(MdfClaim::class)->where('fund_id', $id)->orderByDesc('created_at'));
    }

    #[OA\Post(path: '/api/v1/crm/mdf-funds/{id}/claims', operationId: 'createCrmMdfClaim', description: 'A claim cannot exceed what is left in the fund (allocated minus approved and paid claims).', summary: 'Submit an MDF claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmMdfClaimRequest')), tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmMdfClaim')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function submitClaim(SubmitMdfClaimRequest $request, string $id): JsonResponse
    {
        $fund = $this->find(MdfFund::class, $id, 'update');

        return $this->present($this->mdf->submitClaim($fund, $request->validated()), 201);
    }
}
