<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\FieldService\SaveWorkOrder;
use App\Actions\FieldService\SignWorkOrder;
use App\Actions\Sales\ConvertToInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldService\SignWorkOrderRequest;
use App\Http\Requests\FieldService\StoreWorkOrderRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\WorkOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class WorkOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaveWorkOrder $save,
        private readonly SignWorkOrder $sign,
        private readonly ConvertToInvoice $toInvoice,
    ) {}

    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource($this->save->create($request->validated())),
            __('work_orders.messages.created'),
            Response::HTTP_CREATED
        );
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource(WorkOrder::with(['client', 'lines'])->findOrFail($id)),
            __('work_orders.messages.retrieved')
        );
    }

    public function update(StoreWorkOrderRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource($this->save->update(WorkOrder::findOrFail($id), $request->validated())),
            __('work_orders.messages.updated')
        );
    }

    public function complete(string $id): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource($this->save->complete(WorkOrder::findOrFail($id))),
            __('work_orders.messages.completed')
        );
    }

    /**
     * Store the customer's signature (tablet or smartphone) on the work report.
     */
    public function signature(SignWorkOrderRequest $request, string $id): JsonResponse
    {
        $workOrder = $this->sign->execute(WorkOrder::findOrFail($id), $request->validated('signed_by'), $request->validated('signature_base64'));

        return $this->successResponse(new WorkOrderResource($workOrder), __('work_orders.messages.signed'));
    }

    public function invoice(string $id): JsonResponse
    {
        $invoice = $this->toInvoice->fromWorkOrder(WorkOrder::with('lines')->findOrFail($id));

        return $this->successResponse(new InvoiceResource($invoice), __('work_orders.messages.invoiced'), Response::HTTP_CREATED);
    }
}
