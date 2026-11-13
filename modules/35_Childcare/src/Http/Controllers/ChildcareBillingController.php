<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Security\CsvFormulaNeutralizer;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Childcare\Http\Requests\ChildcareBillingRequest;
use Modules\Childcare\Http\Resources\ChildcareResource;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\FeePlan;
use Modules\Childcare\Models\MonthlyBill;
use Modules\Childcare\Models\SubsidyRule;
use Modules\Childcare\Services\ChildcareBillingService;
use Modules\Childcare\Services\SubsidyCalculator;
use OpenApi\Attributes as OA;

/**
 * Fee plans, cantonal subsidy rules (applied only once verified), monthly billing runs and the subsidy export.
 */
final class ChildcareBillingController extends Controller
{
    public function __construct(private readonly ChildcareBillingService $billing) {}

    /**
     * Return the fee plans.
     */
    #[OA\Get(path: '/api/v1/childcare/fee-plans', operationId: 'listChildcareFeePlans', description: 'Return the fee plans.', summary: 'List fee plans', security: [['bearerAuth' => []]], tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Fee plans', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function feePlans(): AnonymousResourceCollection
    {
        $this->authorize('bill', Child::class);

        return ChildcareResource::collection(FeePlan::query()->orderBy('name')->get());
    }

    /**
     * Create a fee plan: flat monthly or per attended day, plus a price per meal. Amounts and tax rate are entered
     * by the kindergarten.
     */
    #[OA\Post(path: '/api/v1/childcare/fee-plans', operationId: 'storeChildcareFeePlan', description: 'Create a fee plan: flat monthly or per attended day, plus a price per meal. Amounts and tax rate are entered by the kindergarten.', summary: 'Create fee plan', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareFeePlanRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Fee plan created', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeFeePlan(ChildcareBillingRequest $request): JsonResponse
    {
        $this->authorize('bill', Child::class);

        return (new ChildcareResource(FeePlan::query()->create($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return the cantonal subsidy rules and whether they are verified.
     */
    #[OA\Get(path: '/api/v1/childcare/subsidy-rules', operationId: 'listChildcareSubsidyRules', description: 'Return the cantonal subsidy rules and whether they are verified.', summary: 'List subsidy rules', security: [['bearerAuth' => []]], tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Subsidy rules', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function subsidyRules(): AnonymousResourceCollection
    {
        $this->authorize('bill', Child::class);

        return ChildcareResource::collection(SubsidyRule::query()->orderBy('canton')->orderByDesc('valid_from')->get());
    }

    /**
     * Enter the parameters of a cantonal subsidy rule. It is not applied until verified against its official source.
     */
    #[OA\Post(path: '/api/v1/childcare/subsidy-rules', operationId: 'storeChildcareSubsidyRule', description: 'Enter the parameters of a cantonal subsidy rule. It is not applied until verified against its official source.', summary: 'Enter subsidy rule', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareSubsidyRuleRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Rule entered, unverified', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeSubsidyRule(ChildcareBillingRequest $request, SubsidyCalculator $calculator): JsonResponse
    {
        $this->authorize('manageRecords', Child::class);
        $data = $request->validated();
        $calculator->assertValidParameters((string) $data['method'], (array) $data['parameters']);

        return (new ChildcareResource(SubsidyRule::query()->create($data)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Record that the rule was checked against its official source; from now on it is applied.
     */
    #[OA\Post(path: '/api/v1/childcare/subsidy-rules/{id}/verify', operationId: 'verifyChildcareSubsidyRule', description: 'Record that the rule was checked against its official source; from now on it is applied.', summary: 'Verify subsidy rule', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareVerifyRuleRequest')), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Rule verified', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function verifySubsidyRule(ChildcareBillingRequest $request, string $id): ChildcareResource
    {
        $rule = SubsidyRule::query()->findOrFail($id);
        $this->authorize('manageRecords', $rule);

        if ($rule->isVerified()) {
            throw new BusinessRuleException('The rule is already verified.');
        }

        $rule->update(['source_reference' => $request->validated('source_reference'), 'verified_at' => now(), 'verified_by' => $request->user()?->getKey()]);

        return new ChildcareResource($rule);
    }

    /**
     * Bill the fees of a month to every child, net of the subsidy, once per child; children that cannot be billed
     * safely are listed with the reason.
     */
    #[OA\Post(path: '/api/v1/childcare/billing/runs', operationId: 'runChildcareBilling', description: 'Bill the fees of a month to every child, net of the subsidy, once per child; children that cannot be billed safely are listed with the reason.', summary: 'Run monthly billing', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareBillingRunRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Run summary', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function run(ChildcareBillingRequest $request): JsonResponse
    {
        $this->authorize('bill', Child::class);

        return response()->json(['data' => $this->billing->run((string) $request->validated('period'), (string) $request->validated('date'))]);
    }

    /**
     * Return the monthly bills of a period.
     */
    #[OA\Get(path: '/api/v1/childcare/billing/bills', operationId: 'listChildcareMonthlyBills', description: 'Return the monthly bills of a period.', summary: 'List monthly bills', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(name: 'period', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '2026-11')),
        new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 200)),
    ], responses: [
        new OA\Response(response: 200, description: 'Bills', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function bills(ChildcareBillingRequest $request): AnonymousResourceCollection
    {
        $this->authorize('bill', Child::class);

        $bills = MonthlyBill::query()
            ->with(['child.billingContact', 'invoice'])
            ->where('period', (string) $request->validated('period'))
            ->orderBy('created_at')
            ->gridPaginate(RequestedPerPage::resolve(25));
        $bills->getCollection()->each->append(['child_name', 'contact_id', 'contact_name', 'status', 'currency']);

        return ChildcareResource::collection($bills);
    }

    /**
     * Export the subsidies of a month as CSV, one line per child, for the cantonal office. Contains personal data:
     * management only.
     */
    #[OA\Get(path: '/api/v1/childcare/billing/subsidies/export', operationId: 'exportChildcareSubsidies', description: 'Export the subsidies of a month as CSV, one line per child, for the cantonal office. Contains personal data: management only.', summary: 'Export subsidies', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [
        new OA\Parameter(name: 'period', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '2026-11')),
        new OA\Parameter(name: 'canton', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'TI')),
    ], responses: [
        new OA\Response(response: 200, description: 'CSV', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function exportSubsidies(ChildcareBillingRequest $request): Response
    {
        $this->authorize('manageRecords', Child::class);
        $rows = $this->billing->subsidyExport((string) $request->validated('period'), $request->validated('canton'));
        $columns = ['reference_code', 'last_name', 'first_name', 'birth_date', 'canton', 'rule', 'source_reference', 'period', 'attended_days', 'gross_amount', 'subsidy_amount'];
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, $columns, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $column): string => CsvFormulaNeutralizer::neutralize($row[$column]), $columns), ';', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="subsidies-'.$request->validated('period').'.csv"']);
    }
}
