<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\GenerateInspectionsRequest;
use Modules\Facility\Http\Requests\InspectionTemplateRequest;
use Modules\Facility\Http\Resources\InspectionResource;
use Modules\Facility\Http\Resources\InspectionTemplateResource;
use Modules\Facility\Models\FacilityInspectionTemplate;
use Modules\Facility\Services\InspectionService;

/**
 * Recurring inspection templates and the manual run of the scheduler. Operations are documented in
 * FacilityInspectionOpenApi.
 */
final class InspectionTemplateController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly InspectionService $inspections) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('operate', FacilityInspectionTemplate::class);

        return InspectionTemplateResource::collection(
            FacilityInspectionTemplate::query()
                ->when($request->filled('active'), static fn ($query) => $query->where('is_active', $request->boolean('active')))
                ->orderBy('next_due_date')
                ->gridPaginate($this->perPage())
        );
    }

    public function store(InspectionTemplateRequest $request): JsonResponse
    {
        $this->authorize('manage', FacilityInspectionTemplate::class);

        return (new InspectionTemplateResource($this->inspections->createTemplate($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): InspectionTemplateResource
    {
        $template = FacilityInspectionTemplate::query()->findOrFail($id);
        $this->authorize('view', $template);

        return new InspectionTemplateResource($template);
    }

    public function update(InspectionTemplateRequest $request, string $id): InspectionTemplateResource
    {
        $template = FacilityInspectionTemplate::query()->findOrFail($id);
        $this->authorize('manage', $template);

        return new InspectionTemplateResource($this->inspections->updateTemplate($template, $request->validated()));
    }

    public function destroy(string $id): Response
    {
        $template = FacilityInspectionTemplate::query()->findOrFail($id);
        $this->authorize('manage', $template);
        $template->delete();

        return response()->noContent();
    }

    public function generate(GenerateInspectionsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('manage', FacilityInspectionTemplate::class);
        $data = $request->validated();

        $created = $this->inspections->generateDue(
            $data['as_of'] ?? null,
            $data['template_id'] ?? null,
        );

        return InspectionResource::collection($created);
    }
}
