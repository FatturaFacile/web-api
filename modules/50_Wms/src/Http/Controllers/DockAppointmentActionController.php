<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Resources\DockAppointmentResource;
use Modules\Wms\Models\WmsDockAppointment;
use Modules\Wms\Services\DockAppointmentService;
use OpenApi\Attributes as OA;

/**
 * The life of a dock appointment: check-in, completion, cancellation and no-show.
 */
final class DockAppointmentActionController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly DockAppointmentService $appointments) {}

    #[OA\Post(path: '/api/v1/wms/dock-appointments/{id}/check-in', operationId: 'checkInWmsDockAppointment', description: 'The carrier arrived: a scheduled appointment becomes checked in.', summary: 'Check in the carrier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockAppointment')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function checkIn(string $id): DockAppointmentResource
    {
        $record = WmsDockAppointment::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new DockAppointmentResource($this->appointments->checkIn($record));
    }

    #[OA\Post(path: '/api/v1/wms/dock-appointments/{id}/complete', operationId: 'completeWmsDockAppointment', description: 'Loading or unloading is done: a checked-in appointment becomes completed and frees the door.', summary: 'Complete an appointment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockAppointment')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function complete(string $id): DockAppointmentResource
    {
        $record = WmsDockAppointment::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new DockAppointmentResource($this->appointments->complete($record));
    }

    #[OA\Post(path: '/api/v1/wms/dock-appointments/{id}/cancel', operationId: 'cancelWmsDockAppointment', description: 'Cancels a scheduled appointment and frees the slot.', summary: 'Cancel an appointment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockAppointment')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): DockAppointmentResource
    {
        $record = WmsDockAppointment::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new DockAppointmentResource($this->appointments->cancel($record));
    }

    #[OA\Post(path: '/api/v1/wms/dock-appointments/{id}/no-show', operationId: 'noShowWmsDockAppointment', description: 'The carrier did not come: a scheduled appointment whose start has passed becomes no-show and frees the slot.', summary: 'Mark a no-show', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockAppointment')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function noShow(string $id): DockAppointmentResource
    {
        $record = WmsDockAppointment::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new DockAppointmentResource($this->appointments->noShow($record));
    }
}
