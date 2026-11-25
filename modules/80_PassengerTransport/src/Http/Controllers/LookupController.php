<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\HumanResources\Models\Employee;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Enums\DddFileType;
use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Enums\ExchangeDirection;
use Modules\PassengerTransport\Domain\Enums\FlightStatus;
use Modules\PassengerTransport\Domain\Enums\ServiceType;
use Modules\PassengerTransport\Domain\Enums\SheetStatus;
use Modules\PassengerTransport\Domain\Enums\VatLineKind;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\RegulatoryStrategyResolver;
use OpenApi\Attributes as OA;

/**
 * Enumerations, airports, border crossings, the fiscal context of the company and the pickers of drivers and contacts.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    /**
     * The airports served by the cross-border transfers (IATA code, country, coordinates).
     *
     * @var list<array{iata: string, country: string, lat: float, lng: float}>
     */
    private const array AIRPORTS = [
        ['iata' => 'MXP', 'country' => 'IT', 'lat' => 45.6306, 'lng' => 8.7281],
        ['iata' => 'LIN', 'country' => 'IT', 'lat' => 45.4451, 'lng' => 9.2767],
        ['iata' => 'BGY', 'country' => 'IT', 'lat' => 45.6739, 'lng' => 9.7042],
        ['iata' => 'ZRH', 'country' => 'CH', 'lat' => 47.4647, 'lng' => 8.5492],
        ['iata' => 'GVA', 'country' => 'CH', 'lat' => 46.2370, 'lng' => 6.1092],
        ['iata' => 'LUG', 'country' => 'CH', 'lat' => 46.0043, 'lng' => 8.9106],
    ];

    #[OA\Get(path: '/api/v1/passenger-transport/lookups', operationId: 'passengerTransportLookups', summary: 'Enumerations, airports, border crossings and the fiscal context of the company', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(RegulatoryStrategyResolver $strategies): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);

        return $this->successResponse([
            'service_types' => ServiceType::values(),
            'booking_statuses' => BookingStatus::values(),
            'flight_statuses' => FlightStatus::values(),
            'vehicle_categories' => VehicleCategory::values(),
            'driving_regimes' => DrivingRegime::values(),
            'sheet_statuses' => SheetStatus::values(),
            'exchange_directions' => ExchangeDirection::values(),
            'ddd_file_types' => DddFileType::values(),
            'vat_line_kinds' => VatLineKind::values(),
            'currencies' => ['CHF', 'EUR'],
            'border_crossings' => array_map(static fn (BorderCrossing $crossing): array => ['value' => $crossing->value, ...($crossing->coordinates() ?? ['lat' => null, 'lng' => null])], BorderCrossing::cases()),
            'airports' => self::AIRPORTS,
            'jurisdiction' => $strategies->jurisdictionOfCurrentTenant()->value,
            'vat' => ['it_rate' => config('passenger_transport.vat.it_rate'), 'ch_rate' => config('passenger_transport.vat.ch_standard_rate'), 'it_foreign_nature' => config('passenger_transport.vat.it_foreign_nature')],
        ], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/lookups/drivers', operationId: 'passengerTransportLookupDrivers', summary: 'Employees that can be set up as drivers', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Employees')])]
    public function drivers(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);
        $term = '%'.mb_strtolower((string) $request->query('q', '')).'%';

        $rows = Employee::query()
            ->whereRaw('LOWER(full_name) LIKE ?', [$term])
            ->orderBy('full_name')
            ->limit(30)
            ->get(['id', 'full_name'])
            ->map(static fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->full_name]);

        return $this->successResponse($rows, $this->message('listed'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/lookups/contacts', operationId: 'passengerTransportLookupContacts', summary: 'Customers, hotels, agencies, municipalities and partner carriers', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Contacts')])]
    public function contacts(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PassengerBooking::class);
        $term = '%'.mb_strtolower((string) $request->query('q', '')).'%';

        $rows = Contact::query()
            ->whereRaw('LOWER(company_name) LIKE ?', [$term])
            ->orderBy('company_name')
            ->limit(30)
            ->get(['id', 'company_name', 'type'])
            ->map(static fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->company_name, 'type' => $contact->type]);

        return $this->successResponse($rows, $this->message('listed'));
    }
}
