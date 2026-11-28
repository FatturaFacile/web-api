<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Commands\GenerateRegulatorySubmissionCommand;
use Modules\DairyCheeseManufacturing\Application\Commands\GenerateRegulatorySubmissionHandler;
use Modules\DairyCheeseManufacturing\Application\Queries\PreviewRegulatoryDeclarationHandler;
use Modules\DairyCheeseManufacturing\Application\Queries\PreviewRegulatoryDeclarationQuery;
use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;
use Modules\DairyCheeseManufacturing\Http\Requests\GenerateRegulatoryRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\PreviewRegulatoryRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\RegulatoryActionRequest;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\SubmissionResource;
use Modules\DairyCheeseManufacturing\Models\RegulatorySubmission;
use Modules\DairyCheeseManufacturing\Services\Regulatory\RegulatoryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DBMilch.ch / TSM (CH) and SIAN Registro Telematico Latte (IT) declarations.
 */
final class RegulatoryController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly RegulatoryService $regulatory) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/regulatory/submissions', operationId: 'dairyCheeseRegulatorySubmissionsList', summary: 'Audit log of the generated declarations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Submissions')])]
    public function submissions(ViewRequest $request): JsonResponse
    {
        $query = RegulatorySubmission::query()->orderByDesc('generated_at');

        if ($request->validated('authority') !== null) {
            $query->where('authority', $request->validated('authority'));
        }

        return $this->successResponse(SubmissionResource::collection($query->get()));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/regulatory/preview', operationId: 'dairyCheeseRegulatoryPreview', summary: 'Validate a period and preview the movements to declare', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Validation and movements')])]
    public function preview(PreviewRegulatoryRequest $request, PreviewRegulatoryDeclarationHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new PreviewRegulatoryDeclarationQuery(Authority::from((string) $request->validated('authority')), (string) $request->validated('period'))));
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/regulatory/generate', operationId: 'dairyCheeseRegulatoryGenerate', summary: 'Generate the declaration file (XML or CSV)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 201, description: 'Submission')])]
    public function generate(GenerateRegulatoryRequest $request, GenerateRegulatorySubmissionHandler $handler): JsonResponse
    {
        $submission = $handler->handle(new GenerateRegulatorySubmissionCommand(
            Authority::from((string) $request->validated('authority')), (string) $request->validated('period'), $request->validated('format'), $this->userId($request),
        ));

        return $this->successResponse(new SubmissionResource($submission), $this->message($submission->status === 'failed' ? 'submission_failed' : 'submission_generated'), 201);
    }

    #[OA\Get(path: '/api/v1/dairy-cheese/regulatory/submissions/{id}/download', operationId: 'dairyCheeseRegulatoryDownload', summary: 'Download a generated declaration file', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File')])]
    public function download(string $id): StreamedResponse
    {
        $this->view();
        $file = $this->regulatory->download(RegulatorySubmission::query()->findOrFail($id));

        return response()->streamDownload(static function () use ($file): void {
            echo $file['content'];
        }, $file['file_name'], ['Content-Type' => $file['mime']]);
    }

    #[OA\Post(path: '/api/v1/dairy-cheese/regulatory/submissions/{id}/mark-submitted', operationId: 'dairyCheeseRegulatoryMarkSubmitted', summary: 'Record that the declaration was submitted to the authority', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Submission updated')])]
    public function markSubmitted(RegulatoryActionRequest $request, string $id): JsonResponse
    {
        $submission = $this->regulatory->markSubmitted(RegulatorySubmission::query()->findOrFail($id));

        return $this->successResponse(new SubmissionResource($submission), $this->message('submission_marked'));
    }
}
