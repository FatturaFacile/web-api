<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Beekeeping\Application\Commands\RegisterVarroaTreatmentCommand;
use Modules\Beekeeping\Application\Commands\RegisterVarroaTreatmentHandler;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\RegisterTreatmentRequest;
use Modules\Beekeeping\Http\Requests\SaveVarroaCountRequest;
use Modules\Beekeeping\Http\Resources\TreatmentResource;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\VarroaCount;
use Modules\Beekeeping\Models\VarroaTreatment;
use Modules\Beekeeping\Services\VarroaRegisterService;
use OpenApi\Attributes as OA;

/**
 * Veterinary anti-varroa treatment register, withdrawal locks and mite drop counts.
 */
final class VarroaController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly RegisterVarroaTreatmentHandler $register, private readonly VarroaRegisterService $service) {}

    #[OA\Get(path: '/api/v1/beekeeping/varroa/treatments', operationId: 'beekeepingTreatmentsList', summary: 'Treatment register', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Treatments')])]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRead();
        $apiaryId = $request->query('apiary_id');
        $year = $request->query('year');
        $treatments = VarroaTreatment::query()->with('apiary:id,code,name')
            ->when(is_string($apiaryId) && $apiaryId !== '', static fn ($query) => $query->where('apiary_id', $apiaryId))
            ->when(is_numeric($year), static fn ($query) => $query->whereYear('treatment_date', (int) $year))
            ->orderByDesc('treatment_date')->get();

        return $this->successResponse(TreatmentResource::collection($treatments));
    }

    #[OA\Post(path: '/api/v1/beekeeping/varroa/treatments', operationId: 'beekeepingTreatmentsCreate', summary: 'Register a treatment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Treatment registered')])]
    public function store(RegisterTreatmentRequest $request): JsonResponse
    {
        $treatment = $this->register->handle(new RegisterVarroaTreatmentCommand($request->validated() + ['created_by' => $this->userId($request)]));

        return $this->successResponse(new TreatmentResource($treatment->load('apiary:id,code,name')), $this->message('treatment_registered'), 201);
    }

    #[OA\Get(path: '/api/v1/beekeeping/apiaries/{id}/locks', operationId: 'beekeepingApiaryLocks', summary: 'Hives of an apiary locked by a withdrawal period today', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Locks')])]
    public function locks(string $id): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse($this->service->locksOfApiary(Apiary::query()->findOrFail($id)->id));
    }

    #[OA\Get(path: '/api/v1/beekeeping/varroa/counts', operationId: 'beekeepingCountsList', summary: 'Mite drop counts of a hive', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Counts')])]
    public function counts(Request $request): JsonResponse
    {
        $this->authorizeRead();
        $hiveId = $request->query('hive_id');

        return $this->successResponse(VarroaCount::query()->when(is_string($hiveId) && $hiveId !== '', static fn ($query) => $query->where('hive_id', $hiveId))->orderByDesc('counted_on')->limit(200)->get()->map(static fn (VarroaCount $count): array => [
            'id' => $count->id,
            'hive_id' => $count->hive_id,
            'treatment_id' => $count->treatment_id,
            'counted_on' => $count->counted_on->toDateString(),
            'count_type' => $count->count_type,
            'mites_per_day' => $count->mites_per_day,
        ])->all());
    }

    #[OA\Post(path: '/api/v1/beekeeping/varroa/counts', operationId: 'beekeepingCountsCreate', summary: 'Record a mite drop count', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Count recorded')])]
    public function storeCount(SaveVarroaCountRequest $request): JsonResponse
    {
        $count = new VarroaCount($request->validated() + ['created_by' => $this->userId($request)]);
        $count->save();

        return $this->successResponse(['id' => $count->id, 'mites_per_day' => $count->mites_per_day], $this->message('saved'), 201);
    }
}
