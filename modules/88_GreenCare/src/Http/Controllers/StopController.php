<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Commands\LogCrewSiteInterventionCommand;
use Modules\GreenCare\Application\Handlers\LogCrewSiteInterventionHandler;
use Modules\GreenCare\Application\Services\PhotoStore;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\InterventionRequest;
use Modules\GreenCare\Http\Requests\UploadPhotosRequest;
use Modules\GreenCare\Http\Resources\StopResource;
use Modules\GreenCare\Models\CrewRouteStop;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stops of the routes: the one-tap close-out of the crew (mobile ready) and the photos of the intervention.
 */
final class StopController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array WITH = ['site', 'contract'];

    #[OA\Post(path: '/api/v1/green-care/route-stops/{id}/intervention', operationId: 'greenCareStopsIntervention', summary: 'Close a stop: check-in/out with GPS, hours, materials, green waste and the passage on the contract', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Stop')])]
    public function intervention(InterventionRequest $request, string $id, LogCrewSiteInterventionHandler $handler): JsonResponse
    {
        $stop = CrewRouteStop::query()->findOrFail($id);
        Gate::authorize('update', $stop);
        $data = $request->validated();

        $closed = $handler->handle(new LogCrewSiteInterventionCommand(
            stopId: (string) $stop->id,
            checkinAt: isset($data['checkin_at']) ? Carbon::parse($data['checkin_at']) : null,
            checkoutAt: isset($data['checkout_at']) ? Carbon::parse($data['checkout_at']) : null,
            checkinGps: $data['checkin_gps'] ?? null,
            checkoutGps: $data['checkout_gps'] ?? null,
            crewHours: array_values($data['crew_hours'] ?? []),
            machineryHours: array_values($data['machinery_hours'] ?? []),
            materials: array_values($data['materials'] ?? []),
            countsTowardContractPassage: array_key_exists('counts_toward_contract_passage', $data) ? (bool) $data['counts_toward_contract_passage'] : null,
            greenWasteWeightKg: isset($data['green_waste_weight_kg']) ? (float) $data['green_waste_weight_kg'] : null,
            wasteCode: $data['waste_code'] ?? null,
            compostFacilityId: $data['compost_facility_id'] ?? null,
        ));

        return $this->successResponse(new StopResource($closed->load(self::WITH)), $this->message('intervention_logged'));
    }

    #[OA\Post(path: '/api/v1/green-care/route-stops/{id}/photos', operationId: 'greenCareStopsPhotosUpload', summary: 'Upload before/after photos of a stop', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Stop')])]
    public function uploadPhotos(UploadPhotosRequest $request, string $id, PhotoStore $store): JsonResponse
    {
        $stop = CrewRouteStop::query()->findOrFail($id);
        Gate::authorize('update', $stop);
        $data = $request->validated();
        $column = $data['type'] === 'before' ? 'before_photos' : 'after_photos';
        $photos = $stop->{$column} ?? [];

        foreach ($request->file('files', []) as $file) {
            $photos[] = $store->store($file, $this->tenantId(), (string) $stop->id);
        }

        $stop->{$column} = $photos;
        $stop->save();

        return $this->successResponse(new StopResource($stop->refresh()->load(self::WITH)), $this->message('photos_saved'));
    }

    #[OA\Get(path: '/api/v1/green-care/route-stops/{id}/photos/{type}/{index}', operationId: 'greenCareStopsPhotosDownload', summary: 'Download a photo of a stop', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function downloadPhoto(string $id, string $type, int $index, PhotoStore $store): StreamedResponse
    {
        $stop = CrewRouteStop::query()->findOrFail($id);
        Gate::authorize('view', $stop);
        $photos = array_values(($type === 'before' ? $stop->before_photos : $stop->after_photos) ?? []);
        $path = $photos[$index] ?? null;

        if ($path === null) {
            throw GreenCareException::because('file_not_found', [], 404);
        }

        return $store->download($path, $type.'-'.($index + 1).'.'.pathinfo($path, PATHINFO_EXTENSION));
    }
}
