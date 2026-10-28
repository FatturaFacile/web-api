<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Treasury\Actions\CreatePaymentBatchAction;
use Modules\Treasury\Actions\ExecutePaymentBatchAction;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Http\Requests\StorePaymentBatchRequest;
use Modules\Treasury\Http\Resources\PaymentBatchResource;
use Modules\Treasury\Models\PaymentBatch;

final class PaymentBatchController extends Controller
{
    public function __construct(
        private readonly PaymentFactoryServiceInterface $paymentFactory,
        private readonly CreatePaymentBatchAction $createAction,
        private readonly ExecutePaymentBatchAction $executeAction
    ) {}

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = PaymentBatch::where('tenant_id', $tenantId)->with('lines');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->has('bank_account_id')) {
            $query->where('bank_account_id', $request->query('bank_account_id'));
        }

        $batches = $query->latest('execution_date')->paginate((int) $request->query('per_page', 25));

        return response()->json(PaymentBatchResource::collection($batches)->response()->getData(true));
    }

    public function store(StorePaymentBatchRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = $tenantContext->getTenantId();
        $lines = $data['lines'];
        unset($data['lines']);

        $batch = $this->createAction->execute($data, $lines, (string) $request->user()?->id);

        return response()->json(new PaymentBatchResource($batch), 201);
    }

    public function show(string $id): JsonResponse
    {
        $batch = PaymentBatch::with(['lines', 'approvals', 'executions'])->findOrFail($id);

        return response()->json(new PaymentBatchResource($batch));
    }

    public function submitApproval(Request $request, string $id): JsonResponse
    {
        $batch = $this->paymentFactory->submitForApproval($id, (string) $request->user()?->id);

        return response()->json(new PaymentBatchResource($batch));
    }

    public function execute(Request $request, string $id): JsonResponse
    {
        $protocol = (string) $request->input('protocol', 'sepa_pain001');
        $execution = $this->executeAction->execute($id, $protocol, (string) $request->user()?->id);

        return response()->json([
            'message' => 'Payment batch successfully transmitted to banking network.',
            'execution_id' => $execution->id,
            'bank_transmission_reference' => $execution->bank_transmission_reference,
            'clearing_timestamp' => $execution->clearing_timestamp?->toIso8601String(),
        ]);
    }

    public function downloadXml(string $id): Response
    {
        $xml = $this->paymentFactory->generatePain001Xml($id);
        $batch = PaymentBatch::findOrFail($id);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$batch->batch_reference}.xml\"",
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $reason = (string) $request->input('reason', 'Cancelled by Treasury operator');
        $batch = $this->paymentFactory->cancelBatch($id, $reason, (string) $request->user()?->id);

        return response()->json(new PaymentBatchResource($batch));
    }
}
