<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\PassengerTransport\Application\Commands\AssignDriverAndVehicleWithOlr561CheckCommand;
use Modules\PassengerTransport\Application\Commands\AssignDriverAndVehicleWithOlr561CheckHandler;
use Modules\PassengerTransport\Application\Commands\SubcontractServiceExchangeCommand;
use Modules\PassengerTransport\Application\Commands\SubcontractServiceExchangeHandler;
use Modules\PassengerTransport\Application\Commands\TrackFlightArrivalAndAdjustPickupScheduleCommand;
use Modules\PassengerTransport\Application\Commands\TrackFlightArrivalAndAdjustPickupScheduleHandler;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Http\Requests\AssignRequest;
use Modules\PassengerTransport\Http\Requests\ListFiltersRequest;
use Modules\PassengerTransport\Http\Requests\SaveBookingRequest;
use Modules\PassengerTransport\Http\Requests\SubcontractRequest;
use Modules\PassengerTransport\Http\Requests\TransitionRequest;
use Modules\PassengerTransport\Http\Requests\WelcomeLogoRequest;
use Modules\PassengerTransport\Http\Resources\BookingResource;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\BookingInvoicingService;
use Modules\PassengerTransport\Services\BookingService;
use Modules\PassengerTransport\Services\Documents\PassengerTransportDocuments;
use OpenApi\Attributes as OA;

/**
 * Bookings of the passenger transport: transfers, hourly disposals, coach tours, school bus runs and medical transport.
 */
final class BookingController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/passenger-transport/bookings', operationId: 'passengerTransportListBookings', summary: 'List bookings of the company', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Bookings, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);

        $page = PassengerBooking::query()
            ->with(['orderingCustomer:id,company_name', 'vehicle:id,license_plate', 'driver:id,full_name', 'carrier:id,company_name'])
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->when($request->input('service_type'), fn ($rows, $type) => $rows->where('service_type', $type))
            ->when($request->input('customer_id'), fn ($rows, $id) => $rows->where('ordering_customer_id', $id))
            ->when($request->input('driver_employee_id'), fn ($rows, $id) => $rows->where('assigned_driver_id', $id))
            ->when($request->input('from'), fn ($rows, $from) => $rows->where('pickup_datetime', '>=', $from))
            ->when($request->input('to'), fn ($rows, $to) => $rows->where('pickup_datetime', '<=', $to))
            ->when($request->input('q'), function ($rows, $term): void {
                $like = '%'.mb_strtolower((string) $term).'%';
                $rows->where(fn ($inner) => $inner->whereRaw('LOWER(booking_code) LIKE ?', [$like])->orWhereRaw('LOWER(lead_passenger_name) LIKE ?', [$like])->orWhereRaw('LOWER(flight_or_train_number) LIKE ?', [$like]));
            })
            ->orderByDesc('pickup_datetime')
            ->paginate($this->perPage());

        return $this->paged($page, BookingResource::class);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings', operationId: 'passengerTransportCreateBooking', summary: 'Create a booking', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 201, description: 'Booking created')])]
    public function store(SaveBookingRequest $request, BookingService $bookings): JsonResponse
    {
        Gate::authorize('create', PassengerBooking::class);

        return $this->successResponse(new BookingResource($bookings->create($this->tenantId(), $request->validated(), $this->userId())), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/quote', operationId: 'passengerTransportQuoteBooking', summary: 'Price a trip: kilometres by country and VAT split, without saving', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Route split and VAT breakdown')])]
    public function quote(SaveBookingRequest $request, BookingService $bookings): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);

        return $this->successResponse($bookings->quote($request->validated()), $this->message('quoted'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/bookings/{id}', operationId: 'passengerTransportShowBooking', summary: 'Show a booking', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Booking')])]
    public function show(string $id): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('view', $booking);

        return $this->successResponse(new BookingResource($booking), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/passenger-transport/bookings/{id}', operationId: 'passengerTransportUpdateBooking', summary: 'Update a booking that is still open', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Booking updated')])]
    public function update(SaveBookingRequest $request, string $id, BookingService $bookings): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('update', $booking);

        return $this->successResponse(new BookingResource($bookings->update($booking, $request->validated(), $this->userId())), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/passenger-transport/bookings/{id}', operationId: 'passengerTransportDeleteBooking', summary: 'Delete a draft booking', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Booking deleted')])]
    public function destroy(string $id, BookingService $bookings): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('delete', $booking);
        $bookings->delete($booking, $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/transition', operationId: 'passengerTransportTransitionBooking', summary: 'Confirm, run, board, complete or cancel a booking', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Booking moved')])]
    public function transition(TransitionRequest $request, string $id, BookingService $bookings): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('update', $booking);

        return $this->successResponse(new BookingResource($bookings->transition($booking, (string) $request->validated('action'), $this->userId())), $this->message('transitioned'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/assign', operationId: 'passengerTransportAssignBooking', summary: 'Assign a driver and a vehicle after the OLR 1 / OLR 2 / EC 561 check (dry_run only reports)', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Compliance result'), new OA\Response(response: 422, description: 'Blocking violations')])]
    public function assign(AssignRequest $request, string $id, AssignDriverAndVehicleWithOlr561CheckHandler $handler): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('update', $booking);
        $data = $request->validated();
        $result = $handler->handle(new AssignDriverAndVehicleWithOlr561CheckCommand(
            $id,
            (string) $data['vehicle_id'],
            (string) $data['driver_employee_id'],
            (int) ($data['planned_break_minutes'] ?? 0),
            (bool) ($data['acknowledge_warnings'] ?? false),
            (bool) ($data['dry_run'] ?? false),
            $this->userId(),
        ));

        return $this->successResponse(['compliance' => $result, 'booking' => new BookingResource($this->find($id))], $this->message(($data['dry_run'] ?? false) ? 'checked' : 'assigned'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/track-flight', operationId: 'passengerTransportTrackFlight', summary: 'Read the flight status and adjust the pickup', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Flight status and schedule changes')])]
    public function trackFlight(string $id, TrackFlightArrivalAndAdjustPickupScheduleHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->find($id));

        return $this->successResponse(['flight' => $handler->handle(new TrackFlightArrivalAndAdjustPickupScheduleCommand($id)), 'booking' => new BookingResource($this->find($id))], $this->message('flight_tracked'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/subcontract', operationId: 'passengerTransportSubcontractBooking', summary: 'Cede, acquire or release a trip in the service exchange with a partner carrier', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Booking exchanged')])]
    public function subcontract(SubcontractRequest $request, string $id, SubcontractServiceExchangeHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->find($id));
        $data = $request->validated();
        $booking = $handler->handle(new SubcontractServiceExchangeCommand($id, (string) $data['action'], $data['carrier_id'] ?? null, isset($data['amount']) ? (string) $data['amount'] : null, $this->userId()));

        return $this->successResponse(new BookingResource($booking->load('carrier:id,company_name')), $this->message('exchanged'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/invoice', operationId: 'passengerTransportInvoiceBooking', summary: 'Invoice a completed booking with the VAT split of its territories', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Invoice created')])]
    public function invoice(string $id, BookingInvoicingService $invoicing): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('update', $booking);
        Gate::authorize('bill', PassengerBooking::class);
        $invoice = $invoicing->invoiceBooking($booking, $this->userId());

        return $this->successResponse(['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'total_gross' => (string) $invoice->total_gross, 'booking' => new BookingResource($this->find($id))], $this->message('invoiced'), 201);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/bookings/{id}/welcome-logo', operationId: 'passengerTransportUploadWelcomeLogo', summary: 'Upload the logo of the hotel or agency for the meet and greet sign', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Logo stored')])]
    public function welcomeLogo(WelcomeLogoRequest $request, string $id): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('update', $booking);
        $path = $request->file('logo')->store(sprintf('passenger-transport/%s/welcome-logos', $this->tenantId()), (string) config('passenger_transport.storage_disk'));
        $booking->update(['vip_welcome_logo_path' => $path, 'vip_welcome_sign_text' => $request->validated('vip_welcome_sign_text') ?? $booking->vip_welcome_sign_text, 'updated_by' => $this->userId()]);

        return $this->successResponse(new BookingResource($booking->refresh()), $this->message('saved'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/bookings/{id}/welcome-sign', operationId: 'passengerTransportWelcomeSign', summary: 'The meet and greet welcome sign: text, greeting and logo', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Welcome sign')])]
    public function welcomeSign(string $id, PassengerTransportDocuments $documents): JsonResponse
    {
        $booking = $this->find($id);
        Gate::authorize('view', $booking);

        return $this->successResponse($documents->welcomeSign($booking), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/bookings/{id}/welcome-sign/pdf', operationId: 'passengerTransportWelcomeSignPdf', summary: 'The meet and greet welcome sign as a landscape PDF', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function welcomeSignPdf(string $id, PassengerTransportDocuments $documents): Response
    {
        $booking = $this->find($id);
        Gate::authorize('view', $booking);

        return response($documents->welcomeSignPdf($booking), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="welcome-sign-'.$booking->booking_code.'.pdf"']);
    }

    private function find(string $id): PassengerBooking
    {
        return PassengerBooking::query()->with(['orderingCustomer:id,company_name', 'vehicle:id,license_plate', 'driver:id,full_name', 'carrier:id,company_name'])->findOrFail($id);
    }
}
