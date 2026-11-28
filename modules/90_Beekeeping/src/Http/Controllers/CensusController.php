<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Beekeeping\Application\Commands\GenerateCensusDeclarationCommand;
use Modules\Beekeeping\Application\Commands\GenerateCensusDeclarationHandler;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\GenerateCensusRequest;
use Modules\Beekeeping\Http\Requests\SubmitCensusRequest;
use Modules\Beekeeping\Http\Resources\CensusResource;
use Modules\Beekeeping\Models\CensusDeclaration;
use Modules\Beekeeping\Services\CensusService;
use OpenApi\Attributes as OA;

/**
 * Annual overwintering census declarations (BDA / VETINFO, cantonal veterinary office) with CSV and XML exports.
 */
final class CensusController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly GenerateCensusDeclarationHandler $generator, private readonly CensusService $census) {}

    #[OA\Get(path: '/api/v1/beekeeping/census', operationId: 'beekeepingCensusList', summary: 'List the census declarations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Declarations')])]
    public function index(): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(CensusResource::collection(CensusDeclaration::query()->orderByDesc('census_year')->orderBy('country')->get()));
    }

    #[OA\Post(path: '/api/v1/beekeeping/census/generate', operationId: 'beekeepingCensusGenerate', summary: 'Generate the draft of a census declaration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Declaration generated')])]
    public function generate(GenerateCensusRequest $request): JsonResponse
    {
        $declaration = $this->generator->handle(new GenerateCensusDeclarationCommand((int) $request->validated('census_year'), (string) $request->validated('country')));

        return $this->successResponse(new CensusResource($declaration), $this->message('census_generated'), 201);
    }

    #[OA\Post(path: '/api/v1/beekeeping/census/{id}/submit', operationId: 'beekeepingCensusSubmit', summary: 'Record the submission of a census declaration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Declaration submitted')])]
    public function submit(SubmitCensusRequest $request, string $id): JsonResponse
    {
        $declaration = $this->census->submit(CensusDeclaration::query()->findOrFail($id), $request->validated('submission_reference'));

        return $this->successResponse(new CensusResource($declaration), $this->message('census_submitted'));
    }

    #[OA\Get(path: '/api/v1/beekeeping/census/{id}/export/csv', operationId: 'beekeepingCensusCsv', summary: 'Census as CSV', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'CSV file')])]
    public function csv(string $id): Response
    {
        $this->authorizeRead();
        $declaration = CensusDeclaration::query()->findOrFail($id);

        return response($this->census->csv($declaration), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="census-'.$declaration->country.'-'.$declaration->census_year.'.csv"']);
    }

    #[OA\Get(path: '/api/v1/beekeeping/census/{id}/export/xml', operationId: 'beekeepingCensusXml', summary: 'Census as structured XML', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'XML file')])]
    public function xml(string $id): Response
    {
        $this->authorizeRead();
        $declaration = CensusDeclaration::query()->findOrFail($id);

        return response($this->census->xml($declaration), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="census-'.$declaration->country.'-'.$declaration->census_year.'.xml"']);
    }
}
