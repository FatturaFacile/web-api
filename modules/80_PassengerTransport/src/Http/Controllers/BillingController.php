<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Http\Requests\ListFiltersRequest;
use Modules\PassengerTransport\Http\Requests\MonthlyBillingRequest;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\BookingInvoicingService;
use Modules\PassengerTransport\Services\SubcontractService;
use OpenApi\Attributes as OA;

/**
 * Month-end consolidated invoicing of hotels, corporate accounts and municipalities, and the ledger of the service exchange.
 */
final class BillingController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/passenger-transport/billing/monthly-preview', operationId: 'passengerTransportMonthlyPreview', summary: 'What the month-end run would invoice, by customer', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Customers with billable bookings')])]
    public function preview(MonthlyBillingRequest $request, BookingInvoicingService $invoicing): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);

        return $this->successResponse($invoicing->preview((string) $request->validated('period'), $request->validated('contact_id')), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/billing/monthly-invoices', operationId: 'passengerTransportMonthlyInvoices', summary: 'Create the consolidated invoice of each customer for a month', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 201, description: 'Invoices created')])]
    public function invoiceMonth(MonthlyBillingRequest $request, BookingInvoicingService $invoicing): JsonResponse
    {
        Gate::authorize('bill', PassengerBooking::class);

        return $this->successResponse($invoicing->invoiceMonth((string) $request->validated('period'), $request->validated('contact_ids'), $this->userId()), $this->message('invoiced'), 201);
    }

    #[OA\Get(path: '/api/v1/passenger-transport/subcontract/ledger', operationId: 'passengerTransportSubcontractLedger', summary: 'Ceded and acquired trips with the net balance by partner carrier', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Service exchange ledger')])]
    public function ledger(ListFiltersRequest $request, SubcontractService $subcontracts): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);

        return $this->successResponse($subcontracts->ledger($request->input('period'), $request->input('carrier_id')), $this->message('loaded'));
    }
}
