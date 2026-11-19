<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Http\Requests\HourBankEntryRequest;
use Modules\ServiceManagement\Http\Requests\SaveMaintenanceContractRequest;
use Modules\ServiceManagement\Http\Resources\ContractHourTransactionResource;
use Modules\ServiceManagement\Http\Resources\EquipmentPassportResource;
use Modules\ServiceManagement\Http\Resources\ServiceContractResource;
use Modules\ServiceManagement\Jobs\SendResidualHoursReportJob;
use Modules\ServiceManagement\Models\ContractHourTransaction;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Services\HourBankService;
use OpenApi\Attributes as OA;

/**
 * Recurring maintenance contracts: CRUD with covered assets, hour-bank ledger, manual top-up/adjustment and
 * on-demand residual hours report. Tenant isolation comes from the tenant global scope of the models.
 */
final class MaintenanceContractController extends Controller
{
    private const FIELDS = [
        'customer_id', 'contract_number', 'title', 'start_date', 'end_date', 'status', 'billing_recurrence',
        'annual_value', 'sla_response_hours', 'included_hours', 'low_balance_threshold_percent', 'report_email',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly HourBankService $hourBank,
    ) {}

    #[OA\Post(
        path: '/api/v1/esm/service-contracts',
        operationId: 'createEsmMaintenanceContract',
        description: 'Creates a recurring maintenance contract with its covered assets; the opening hours are ledgered as a top-up.',
        summary: 'Create maintenance contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'), new OA\Property(property: 'contract_number', type: 'string'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'start_date', type: 'string', format: 'date'), new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'suspended', 'expired']), new OA\Property(property: 'billing_recurrence', type: 'string', enum: ['monthly', 'quarterly', 'yearly']), new OA\Property(property: 'included_hours', type: 'number'), new OA\Property(property: 'low_balance_threshold_percent', type: 'integer'), new OA\Property(property: 'report_email', type: 'string', nullable: true), new OA\Property(property: 'asset_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
        responses: [new OA\Response(response: 201, description: 'Contract created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation or hour-bank rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function store(SaveMaintenanceContractRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenant()->id;
        $data = $request->safe()->only(self::FIELDS);
        $openingHours = number_format((float) ($data['included_hours'] ?? 0), 2, '.', '');
        $data['included_hours'] = '0.00';

        $contract = DB::transaction(function () use ($data, $request, $tenantId, $openingHours): ServiceContract {
            $contract = ServiceContract::create($data + [
                'tenant_id' => $tenantId,
                'status' => 'draft',
                'approval_status' => 'approved',
            ]);
            $contract->assets()->sync($this->pivot($request->validated('asset_ids', []), $tenantId));

            // The opening allocation is ledgered like any other top-up so balance and ledger never drift.
            if (bccomp($openingHours, '0', 2) > 0) {
                $this->hourBank->topUp($contract, $openingHours, 'Opening allocation', $request->user()?->id);
            }

            return $contract->fresh() ?? $contract;
        });

        return response()->json(['data' => new ServiceContractResource($contract->load('assets'))], 201);
    }

    #[OA\Put(
        path: '/api/v1/esm/service-contracts/{serviceContract}',
        operationId: 'updateEsmMaintenanceContract',
        description: 'Updates a maintenance contract and, when asset_ids is sent, replaces its covered assets. Hours change only through top-up and adjustment.',
        summary: 'Update maintenance contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [new OA\Parameter(name: 'serviceContract', in: 'path', required: true, description: 'Identifier of the maintenance contract', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'), new OA\Property(property: 'contract_number', type: 'string'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'start_date', type: 'string', format: 'date'), new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'suspended', 'expired']), new OA\Property(property: 'billing_recurrence', type: 'string', enum: ['monthly', 'quarterly', 'yearly']), new OA\Property(property: 'included_hours', type: 'number'), new OA\Property(property: 'low_balance_threshold_percent', type: 'integer'), new OA\Property(property: 'report_email', type: 'string', nullable: true), new OA\Property(property: 'asset_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
        responses: [new OA\Response(response: 200, description: 'Contract updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation or hour-bank rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function update(SaveMaintenanceContractRequest $request, ServiceContract $serviceContract): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenant()->id;
        $data = $request->safe()->only(self::FIELDS);

        // The allocated hours only change through ledgered top-ups/adjustments, never by a plain update.
        unset($data['included_hours']);

        DB::transaction(function () use ($serviceContract, $data, $request, $tenantId): void {
            $serviceContract->fill($data)->save();
            if ($request->has('asset_ids')) {
                $serviceContract->assets()->sync($this->pivot($request->validated('asset_ids', []), $tenantId));
            }
        });

        return response()->json(['data' => new ServiceContractResource($serviceContract->fresh('assets'))]);
    }

    #[OA\Get(
        path: '/api/v1/esm/service-contracts/{serviceContract}/ledger',
        operationId: 'listEsmMaintenanceContractLedger',
        description: 'Returns the immutable hour-bank ledger of the contract, newest first.',
        summary: 'List hour-bank ledger',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [new OA\Parameter(name: 'serviceContract', in: 'path', required: true, description: 'Identifier of the maintenance contract', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Ledger entries', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function ledger(Request $request, ServiceContract $serviceContract): JsonResponse
    {
        $entries = $serviceContract->hourTransactions()->with('asset')->reorder('occurred_at', 'desc')->paginate(50);

        return response()->json([
            'data' => ContractHourTransactionResource::collection($entries->getCollection()),
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/service-contracts/{serviceContract}/hours/top-up',
        operationId: 'topUpEsmMaintenanceContractHours',
        description: 'Adds hours to the contract hour bank and records a ledger entry.',
        summary: 'Top up contract hours',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [new OA\Parameter(name: 'serviceContract', in: 'path', required: true, description: 'Identifier of the maintenance contract', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['hours'], properties: [new OA\Property(property: 'hours', type: 'number', description: 'Decimal hours with at most two decimals'), new OA\Property(property: 'description', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Hours added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation or hour-bank rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function topUp(HourBankEntryRequest $request, ServiceContract $serviceContract): JsonResponse
    {
        $entry = $this->hourBank->topUp($serviceContract, (string) $request->validated('hours'), $request->validated('description'), $request->user()?->id);

        return response()->json([
            'data' => new ContractHourTransactionResource($entry),
            'contract' => new ServiceContractResource($serviceContract->fresh()),
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/esm/service-contracts/{serviceContract}/hours/adjust',
        operationId: 'adjustEsmMaintenanceContractHours',
        description: 'Applies a signed manual correction to the allocated hours; the remaining balance can never become negative.',
        summary: 'Adjust contract hours',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [new OA\Parameter(name: 'serviceContract', in: 'path', required: true, description: 'Identifier of the maintenance contract', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['hours'], properties: [new OA\Property(property: 'hours', type: 'number', description: 'Decimal hours with at most two decimals'), new OA\Property(property: 'description', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Hours adjusted', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation or hour-bank rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function adjust(HourBankEntryRequest $request, ServiceContract $serviceContract): JsonResponse
    {
        $entry = $this->hourBank->adjust($serviceContract, (string) $request->validated('hours'), $request->validated('description'), $request->user()?->id);

        return response()->json([
            'data' => new ContractHourTransactionResource($entry),
            'contract' => new ServiceContractResource($serviceContract->fresh()),
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/esm/service-contracts/{serviceContract}/residual-report',
        operationId: 'sendEsmMaintenanceContractResidualReport',
        description: 'Queues the residual hours statement (PDF and e-mail summary) for the customer.',
        summary: 'Send residual hours report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [new OA\Parameter(name: 'serviceContract', in: 'path', required: true, description: 'Identifier of the maintenance contract', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 202, description: 'Report queued', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function sendReport(ServiceContract $serviceContract): JsonResponse
    {
        SendResidualHoursReportJob::dispatch($serviceContract->id, (string) $serviceContract->tenant_id);

        return response()->json(['message' => 'Residual hours report queued'], 202);
    }

    #[OA\Get(
        path: '/api/v1/esm/equipment-passports/{passport}/history',
        operationId: 'getEsmInstalledAssetHistory',
        description: 'Returns the asset with its maintenance contracts and the chronological timeline of work orders and hour deductions.',
        summary: 'Get installed asset history',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [new OA\Parameter(name: 'passport', in: 'path', required: true, description: 'Identifier of the equipment passport', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Asset history', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function assetHistory(SerializedEquipmentPassport $passport): JsonResponse
    {
        $workOrders = $passport->workOrders()->orderByDesc('created_at')->get()->map(fn ($wo): array => [
            'kind' => 'work_order',
            'id' => $wo->id,
            'reference' => $wo->work_order_number,
            'status' => $wo->status,
            'ticket_id' => $wo->ticket_id,
            'occurred_at' => $wo->created_at?->toIso8601String(),
        ]);
        $hours = ContractHourTransaction::query()
            ->where('equipment_passport_id', $passport->id)->orderByDesc('occurred_at')->get()
            ->map(fn ($tx): array => [
                'kind' => 'hours_'.$tx->type,
                'id' => $tx->id,
                'reference' => $tx->reference,
                'hours' => (string) $tx->hours,
                'occurred_at' => $tx->occurred_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'asset' => new EquipmentPassportResource($passport->load('maintenanceContracts')),
                'timeline' => $workOrders->concat($hours)->sortByDesc('occurred_at')->values(),
            ],
        ]);
    }

    /**
     * @param  list<string>  $assetIds
     * @return array<string, array<string, string>>
     */
    private function pivot(array $assetIds, string $tenantId): array
    {
        $rows = [];
        foreach ($assetIds as $id) {
            $rows[$id] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId];
        }

        return $rows;
    }
}
