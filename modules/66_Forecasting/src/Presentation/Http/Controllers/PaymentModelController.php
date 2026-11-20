<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Forecasting\Application\Commands\TrainCustomerPaymentDelayModelCommand;
use Modules\Forecasting\Application\Handlers\ListPaymentBehaviorModelsHandler;
use Modules\Forecasting\Application\Handlers\TrainCustomerPaymentDelayModelHandler;
use Modules\Forecasting\Presentation\Http\Requests\TrainPaymentModelsRequest;
use OpenApi\Attributes as OA;

final class PaymentModelController extends ForecastingApiController
{
    #[OA\Get(path: '/api/v1/forecasting/payment-models', operationId: 'listForecastingPaymentModels', description: 'Payment behaviour of the customers with the predicted collection dates of their open invoices, worst risk first.', summary: 'List customer payment behaviour models', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], responses: [new OA\Response(response: 200, description: 'The models', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(ListPaymentBehaviorModelsHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle($this->tenantId())));
    }

    #[OA\Post(path: '/api/v1/forecasting/payment-models/train', operationId: 'trainForecastingPaymentModels', description: 'Learns the recency-weighted payment delay of every customer and dates the open receivables with it.', summary: 'Train the customer payment delay models', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Forecasting'], responses: [new OA\Response(response: 200, description: 'Models trained and invoices dated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function train(TrainPaymentModelsRequest $request, TrainCustomerPaymentDelayModelHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new TrainCustomerPaymentDelayModelCommand(
            $this->tenantId(),
            (int) ($data['half_life_days'] ?? 90),
            (int) ($data['lookback_months'] ?? 24),
        )), 'Payment models trained'));
    }
}
