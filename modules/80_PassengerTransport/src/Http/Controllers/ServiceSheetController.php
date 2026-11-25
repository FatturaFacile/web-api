<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\PassengerTransport\Application\Commands\GenerateNccServiceSheetAndSplitVatInvoiceCommand;
use Modules\PassengerTransport\Application\Commands\GenerateNccServiceSheetAndSplitVatInvoiceHandler;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Http\Requests\ServiceSheetRequest;
use Modules\PassengerTransport\Http\Resources\ServiceSheetResource;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\ServiceSheet;
use Modules\PassengerTransport\Services\ServiceSheetService;
use OpenApi\Attributes as OA;

/**
 * The NCC electronic service sheet (FDSE) and the EU Interbus waybill of a booking.
 */
final class ServiceSheetController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/passenger-transport/bookings/{id}/service-sheets', operationId: 'passengerTransportListServiceSheets', summary: 'Service sheets of a booking', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Service sheets')])]
    public function index(string $id): JsonResponse
    {
        $booking = PassengerBooking::query()->findOrFail($id);
        Gate::authorize('view', $booking);

        return $this->successResponse(ServiceSheetResource::collection(ServiceSheet::query()->where('booking_id', $id)->orderBy('progressive_sheet_number')->get()), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/service-sheets', operationId: 'passengerTransportOpenServiceSheet', summary: 'Open the service sheet of an assigned booking with its progressive number', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Sheet opened')])]
    public function store(ServiceSheetRequest $request, string $id, ServiceSheetService $sheets): JsonResponse
    {
        $booking = PassengerBooking::query()->findOrFail($id);
        Gate::authorize('update', $booking);

        return $this->successResponse(new ServiceSheetResource($sheets->open($booking, $request->validated(), $this->userId())), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/service-sheets/generate', operationId: 'passengerTransportGenerateServiceSheet', summary: 'Open or complete the sheet, close and transmit it and invoice the trip with the VAT split', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Sheet and invoice')])]
    public function generate(ServiceSheetRequest $request, string $id, GenerateNccServiceSheetAndSplitVatInvoiceHandler $handler): JsonResponse
    {
        Gate::authorize('update', PassengerBooking::query()->findOrFail($id));
        $data = $request->validated();

        if ($data['invoice'] ?? false) {
            Gate::authorize('bill', PassengerBooking::class);
        }

        $result = $handler->handle(new GenerateNccServiceSheetAndSplitVatInvoiceCommand($id, $data, (bool) ($data['close'] ?? false), (bool) ($data['transmit'] ?? false), (bool) ($data['invoice'] ?? false), $this->userId()));

        return $this->successResponse([
            'sheet' => new ServiceSheetResource($result['sheet']),
            'invoice' => $result['invoice'] === null ? null : ['id' => $result['invoice']->id, 'invoice_number' => $result['invoice']->invoice_number, 'total_gross' => (string) $result['invoice']->total_gross],
        ], $this->message('sheet_generated'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/service-sheets/{id}', operationId: 'passengerTransportShowServiceSheet', summary: 'Show a service sheet', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Service sheet')])]
    public function show(string $id): JsonResponse
    {
        $sheet = ServiceSheet::query()->findOrFail($id);
        Gate::authorize('view', $sheet);

        return $this->successResponse(new ServiceSheetResource($sheet), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/passenger-transport/service-sheets/{id}', operationId: 'passengerTransportUpdateServiceSheet', summary: 'Record the times, kilometres and manifest of an open sheet', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Sheet updated')])]
    public function update(ServiceSheetRequest $request, string $id, ServiceSheetService $sheets): JsonResponse
    {
        $sheet = ServiceSheet::query()->findOrFail($id);
        Gate::authorize('update', $sheet);

        return $this->successResponse(new ServiceSheetResource($sheets->update($sheet, $request->validated(), $this->userId())), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/service-sheets/{id}/close', operationId: 'passengerTransportCloseServiceSheet', summary: 'Validate the mandatory content, sign (SHA-256) and archive the sheet as PDF', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Sheet closed')])]
    public function close(string $id, ServiceSheetService $sheets): JsonResponse
    {
        $sheet = ServiceSheet::query()->findOrFail($id);
        Gate::authorize('update', $sheet);

        return $this->successResponse(new ServiceSheetResource($sheets->close($sheet, $this->userId())), $this->message('sheet_closed'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/service-sheets/{id}/transmit', operationId: 'passengerTransportTransmitServiceSheet', summary: 'Transmit a closed sheet to the MIT register', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Sheet transmitted')])]
    public function transmit(string $id, ServiceSheetService $sheets): JsonResponse
    {
        $sheet = ServiceSheet::query()->findOrFail($id);
        Gate::authorize('update', $sheet);

        return $this->successResponse(new ServiceSheetResource($sheets->transmit($sheet, $this->userId())), $this->message('sheet_transmitted'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/service-sheets/{id}/pdf', operationId: 'passengerTransportServiceSheetPdf', summary: 'The service sheet as PDF', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function pdf(string $id, ServiceSheetService $sheets): Response
    {
        $sheet = ServiceSheet::query()->findOrFail($id);
        Gate::authorize('view', $sheet);

        return response($sheets->pdf($sheet), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$sheet->number().'.pdf"']);
    }

    #[OA\Get(path: '/api/v1/passenger-transport/service-sheets/{id}/interbus-pdf', operationId: 'passengerTransportInterbusPdf', summary: 'The EU Interbus waybill with the passenger manifest as PDF', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function interbusPdf(string $id, ServiceSheetService $sheets): Response
    {
        $sheet = ServiceSheet::query()->findOrFail($id);
        Gate::authorize('view', $sheet);

        return response($sheets->interbusPdf($sheet), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$sheet->interbus_eu_waybill_number.'.pdf"']);
    }
}
