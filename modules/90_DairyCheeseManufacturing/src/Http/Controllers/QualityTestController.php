<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\ImportLabAnalysisCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\ImportLabAnalysisHandler;
use Modules\DairyCheeseManufacturing\Domain\Enums\LabFormat;
use Modules\DairyCheeseManufacturing\Http\Requests\ImportQualityRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\SaveQualityTestRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\QualityTestResource;
use Modules\DairyCheeseManufacturing\Models\QualityTest;
use Modules\DairyCheeseManufacturing\Services\QualityTestService;
use OpenApi\Attributes as OA;

/**
 * Laboratory analyses of the milk.
 */
final class QualityTestController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly QualityTestService $tests) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/quality-tests', operationId: 'dairyCheeseQualityTestsList', summary: 'List the laboratory analyses', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Analyses')])]
    public function index(ViewRequest $request): JsonResponse
    {
        $f = $request->validated();
        $query = QualityTest::query()->with('breeder')->orderByDesc('sample_date')->orderByDesc('created_at');

        if (! empty($f['breeder_id'])) {
            $query->where('breeder_id', $f['breeder_id']);
        }

        if (! empty($f['period'])) {
            $query->whereBetween('sample_date', [$f['period'].'-01', date('Y-m-t', (int) strtotime($f['period'].'-01'))]);
        }

        $page = $query->paginate($this->perPage($request));

        return $this->successResponse(QualityTestResource::collection($page->items()), 'OK', 200, $this->pageMeta($page));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/quality-tests', operationId: 'dairyCheeseQualityTestsCreate', summary: 'Enter a laboratory analysis manually', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Analysis saved')])]
    public function store(SaveQualityTestRequest $request): JsonResponse
    {
        $test = $this->tests->create($request->validated(), $this->userId($request));

        return $this->successResponse(new QualityTestResource($test->load('breeder')), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/quality-tests/import', operationId: 'dairyCheeseQualityTestsImport', summary: 'Preview or commit the import of a laboratory file (Suisselab, TSM, AIA, ARAL, CSV)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Rows, anomalies and summary')])]
    public function import(ImportQualityRequest $request, ImportLabAnalysisHandler $handler): JsonResponse
    {
        $result = $handler->handle(new ImportLabAnalysisCommand(
            (string) $request->file('file')?->getContent(),
            LabFormat::from((string) $request->validated('format')),
            $request->boolean('commit'),
            $this->userId($request),
        ));

        return $this->successResponse($result, $result['committed'] ? $this->message('import_committed') : $this->message('import_previewed'));
    }
}
