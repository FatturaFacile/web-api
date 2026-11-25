<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\PassengerTransport\Application\Commands\UploadAndParseTachographDddCommand;
use Modules\PassengerTransport\Application\Commands\UploadAndParseTachographDddHandler;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Http\Requests\ListFiltersRequest;
use Modules\PassengerTransport\Http\Requests\UploadDddRequest;
use Modules\PassengerTransport\Http\Resources\DddArchiveResource;
use Modules\PassengerTransport\Models\DddArchive;
use Modules\PassengerTransport\Models\DriverTachographEvent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Archive of the tachograph files (.DDD) with the infringement audit history.
 */
final class TachographController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/passenger-transport/tachograph/archives', operationId: 'passengerTransportListDddArchives', summary: 'List the archived tachograph files', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Archives, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DddArchive::class);

        $page = DddArchive::query()
            ->with('driver:id,full_name')
            ->when($request->input('driver_employee_id'), fn ($rows, $id) => $rows->where('driver_employee_id', $id))
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        return $this->paged($page, DddArchiveResource::class);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/tachograph/archives', operationId: 'passengerTransportUploadDdd', summary: 'Upload, parse and archive a tachograph file (.DDD)', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 201, description: 'File archived')])]
    public function store(UploadDddRequest $request, UploadAndParseTachographDddHandler $handler): JsonResponse
    {
        Gate::authorize('create', DddArchive::class);
        $archive = $handler->handle(new UploadAndParseTachographDddCommand($request->file('file'), $request->validated('driver_employee_id'), $request->validated('vehicle_id'), $this->userId()));

        return $this->successResponse(new DddArchiveResource($archive->load('driver:id,full_name')), $this->message('ddd_archived'), 201);
    }

    #[OA\Get(path: '/api/v1/passenger-transport/tachograph/archives/{id}/download', operationId: 'passengerTransportDownloadDdd', summary: 'Download an archived tachograph file', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The original file')])]
    public function download(string $id): StreamedResponse
    {
        $archive = DddArchive::query()->findOrFail($id);
        Gate::authorize('view', $archive);

        return Storage::disk((string) config('passenger_transport.storage_disk'))->download($archive->storage_path, $archive->original_name, ['Content-Type' => 'application/octet-stream']);
    }

    #[OA\Get(path: '/api/v1/passenger-transport/tachograph/infringements', operationId: 'passengerTransportListInfringements', summary: 'Infringements found in the tachograph files', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Infringements, paginated')])]
    public function infringements(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DddArchive::class);

        $page = DriverTachographEvent::query()
            ->where('event_type', DriverTachographEvent::Infringement)
            ->when($request->input('driver_employee_id'), fn ($rows, $id) => $rows->where('driver_employee_id', $id))
            ->orderByDesc('event_date')
            ->paginate($this->perPage());

        return $this->successResponse(
            collect($page->items())->map(static fn (DriverTachographEvent $event): array => [
                'id' => $event->id,
                'driver_employee_id' => $event->driver_employee_id,
                'ddd_archive_id' => $event->ddd_archive_id,
                'event_date' => $event->event_date->toDateString(),
                'code' => $event->infringement_code,
                'minutes' => $event->infringement_minutes,
            ])->values(),
            $this->message('listed'),
            200,
            ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }
}
