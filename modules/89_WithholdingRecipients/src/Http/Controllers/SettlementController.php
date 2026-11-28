<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Application\Commands\PostRecipientPaymentAndScheduleF24Command;
use Modules\WithholdingRecipients\Application\Commands\PostRecipientPaymentAndScheduleF24Handler;
use Modules\WithholdingRecipients\Application\Commands\PostRecipientPaymentBatchCommand;
use Modules\WithholdingRecipients\Application\Commands\PostRecipientPaymentBatchHandler;
use Modules\WithholdingRecipients\Application\Queries\ListTaxRegisterHandler;
use Modules\WithholdingRecipients\Application\Queries\ListTaxRegisterQuery;
use Modules\WithholdingRecipients\Http\Controllers\Concerns\ResolvesWithholdingContext;
use Modules\WithholdingRecipients\Http\Requests\MonthFlowRequest;
use Modules\WithholdingRecipients\Http\Requests\PostPaymentBatchRequest;
use Modules\WithholdingRecipients\Http\Requests\PostPaymentRequest;
use Modules\WithholdingRecipients\Http\Requests\RegisterIndexRequest;
use Modules\WithholdingRecipients\Http\Resources\SettlementResource;
use Modules\WithholdingRecipients\Models\RecipientSettlement;
use Modules\WithholdingRecipients\Services\PaymentFlowExporter;
use OpenApi\Attributes as OA;

/**
 * Settlement centre: tax register of the paid compensations, single and batch posting (cash principle), F24 and UniEmens flows.
 */
final class SettlementController extends Controller
{
    use ApiResponse;
    use ResolvesWithholdingContext;

    public function __construct(
        private readonly PostRecipientPaymentAndScheduleF24Handler $post,
        private readonly PostRecipientPaymentBatchHandler $postBatch,
        private readonly ListTaxRegisterHandler $register,
        private readonly PaymentFlowExporter $flows,
    ) {}

    #[OA\Get(path: '/api/v1/withholding-recipients/settlements', operationId: 'withholdingRecipientsSettlements', summary: 'Tax register of the posted compensations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'Settlements')])]
    public function index(RegisterIndexRequest $request): JsonResponse
    {
        $filters = $request->safe()->except('per_page');
        $page = $this->register->handle(new ListTaxRegisterQuery($filters, (int) ($request->validated('per_page') ?? 25)));

        return $this->successResponse(SettlementResource::collection($page->items()), 'OK', 200, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/settlements/{id}', operationId: 'withholdingRecipientsSettlementShow', summary: 'Show a posted compensation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settlement')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', RecipientSettlement::class);

        return $this->successResponse(new SettlementResource(RecipientSettlement::query()->with('profile')->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/settlements', operationId: 'withholdingRecipientsSettlementPost', summary: 'Post the payment of a compensation and schedule the F24', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 201, description: 'Payment posted')])]
    public function store(PostPaymentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $settlement = $this->post->handle(new PostRecipientPaymentAndScheduleF24Command((string) $data['recipient_profile_id'], $data, $this->userId($request)));

        return $this->successResponse(new SettlementResource($settlement->load('profile')), $this->message('payment_posted'), 201);
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/settlements/batch', operationId: 'withholdingRecipientsSettlementBatch', summary: 'Post several payments in one transaction', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 201, description: 'Payments posted')])]
    public function batch(PostPaymentBatchRequest $request): JsonResponse
    {
        $posted = $this->postBatch->handle(new PostRecipientPaymentBatchCommand(array_values($request->validated('items')), $this->userId($request)));

        return $this->successResponse(SettlementResource::collection(collect($posted)->each->load('profile')), $this->message('payments_posted'), 201);
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/flows/f24', operationId: 'withholdingRecipientsF24Preview', summary: 'F24 preview of a month (1040, 1038, C10)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'F24 lines')])]
    public function f24(MonthFlowRequest $request): JsonResponse
    {
        return $this->successResponse($this->flows->f24Preview((string) $request->validated('accrual_month')));
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/flows/f24/export', operationId: 'withholdingRecipientsF24Export', summary: 'F24 lines of a month as CSV', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'CSV file')])]
    public function f24Export(MonthFlowRequest $request): Response
    {
        $month = (string) $request->validated('accrual_month');

        return response($this->flows->f24Csv($month), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="f24-'.$month.'.csv"']);
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/flows/uniemens', operationId: 'withholdingRecipientsUniEmens', summary: 'UniEmens Gestione Separata flow of a month', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'XML file')])]
    public function uniEmens(MonthFlowRequest $request): Response
    {
        $month = (string) $request->validated('accrual_month');

        return response($this->flows->uniEmensXml($month), 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="uniemens-'.$month.'.xml"']);
    }
}
