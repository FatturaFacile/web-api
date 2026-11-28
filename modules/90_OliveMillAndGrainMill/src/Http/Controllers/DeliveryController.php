<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\OliveMillAndGrainMill\Application\Commands\RegisterOliveDeliveryCommand;
use Modules\OliveMillAndGrainMill\Application\Commands\WeighOliveDeliveryCommand;
use Modules\OliveMillAndGrainMill\Application\Handlers\RegisterOliveDeliveryHandler;
use Modules\OliveMillAndGrainMill\Application\Handlers\WeighOliveDeliveryHandler;
use Modules\OliveMillAndGrainMill\Application\Queries\MillQueries;
use Modules\OliveMillAndGrainMill\Application\Services\SmsReminderService;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Http\Controllers\Concerns\InteractsWithOliveMill;
use Modules\OliveMillAndGrainMill\Http\Requests\SaveDeliveryRequest;
use Modules\OliveMillAndGrainMill\Http\Requests\WeighDeliveryRequest;
use Modules\OliveMillAndGrainMill\Http\Resources\DeliveryResource;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;
use OpenApi\Attributes as OA;

/**
 * Crop deliveries: booking, weighbridge reading and the hopper SMS reminder.
 */
final class DeliveryController extends Controller
{
    use ApiResponse;
    use InteractsWithOliveMill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/olive-mill/olive-deliveries', operationId: 'oliveMillDeliveriesIndex', summary: 'Crop deliveries (filter by status, commodity, mode, campaign, farmer, lot)', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Deliveries')])]
    public function index(MillQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', OliveDelivery::class);

        return $this->paged($queries->deliveries($this->filters(), $this->perPage()), DeliveryResource::class);
    }

    #[OA\Post(path: '/api/v1/olive-mill/olive-deliveries', operationId: 'oliveMillDeliveriesStore', summary: 'Book a delivery, optionally weighing it and reserving the hopper slot', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 201, description: 'Delivery')])]
    public function store(SaveDeliveryRequest $request, RegisterOliveDeliveryHandler $handler): JsonResponse
    {
        Gate::authorize('create', OliveDelivery::class);

        $delivery = $handler->handle(RegisterOliveDeliveryCommand::fromArray($request->validated() + ['id' => null], $this->userId()));

        return $this->createdResponse(new DeliveryResource($delivery->load('farmer')), $this->message('delivery_saved'));
    }

    #[OA\Get(path: '/api/v1/olive-mill/olive-deliveries/{id}', operationId: 'oliveMillDeliveriesShow', summary: 'One delivery with its cycle and settlement', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Delivery')])]
    public function show(string $id, MillQueries $queries): JsonResponse
    {
        $delivery = $queries->delivery($id);
        Gate::authorize('view', $delivery);

        return $this->successResponse(new DeliveryResource($delivery), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/olive-mill/olive-deliveries/{id}', operationId: 'oliveMillDeliveriesUpdate', summary: 'Change a delivery that was not milled yet', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Delivery')])]
    public function update(SaveDeliveryRequest $request, string $id, RegisterOliveDeliveryHandler $handler): JsonResponse
    {
        Gate::authorize('update', OliveDelivery::query()->findOrFail($id));

        $delivery = $handler->handle(RegisterOliveDeliveryCommand::fromArray(['id' => $id] + $request->validated(), $this->userId()));

        return $this->successResponse(new DeliveryResource($delivery->load('farmer')), $this->message('delivery_saved'));
    }

    #[OA\Delete(path: '/api/v1/olive-mill/olive-deliveries/{id}', operationId: 'oliveMillDeliveriesDestroy', summary: 'Cancel a delivery that was not milled yet', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Cancelled')])]
    public function destroy(string $id): JsonResponse
    {
        $delivery = OliveDelivery::query()->findOrFail($id);
        Gate::authorize('delete', $delivery);

        if (! in_array($delivery->status, [DeliveryStatus::Booked, DeliveryStatus::Weighed], true)) {
            throw OliveMillException::because('delivery_locked', ['status' => $delivery->status->value], 409);
        }

        $delivery->forceFill(['status' => DeliveryStatus::Cancelled])->save();
        $delivery->delete();

        return $this->successResponse(null, $this->message('delivery_cancelled'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/olive-deliveries/{id}/weigh', operationId: 'oliveMillDeliveriesWeigh', summary: 'Record the weighbridge reading: net = gross - tare - crates', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Weighed delivery')])]
    public function weigh(WeighDeliveryRequest $request, string $id, WeighOliveDeliveryHandler $handler): JsonResponse
    {
        Gate::authorize('update', OliveDelivery::query()->findOrFail($id));

        $delivery = $handler->handle(WeighOliveDeliveryCommand::fromArray(['delivery_id' => $id] + $request->validated(), $this->userId()));

        return $this->successResponse(new DeliveryResource($delivery->load('farmer')), $this->message('delivery_weighed'));
    }

    #[OA\Post(path: '/api/v1/olive-mill/olive-deliveries/{id}/send-sms-reminder', operationId: 'oliveMillDeliveriesSms', summary: 'Send the hopper SMS reminder to the farmer now', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Delivery with the SMS state')])]
    public function sendSmsReminder(string $id, SmsReminderService $sms): JsonResponse
    {
        $delivery = OliveDelivery::query()->with('farmer:id,company_name,first_name,last_name,phone')->findOrFail($id);
        Gate::authorize('update', $delivery);

        return $this->successResponse(new DeliveryResource($sms->send($delivery)), $this->message('sms_sent'));
    }
}
