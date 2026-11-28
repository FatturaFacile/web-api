<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Application\Commands\GenerateAnnualCuWithholdingReturnCommand;
use Modules\WithholdingRecipients\Application\Commands\GenerateAnnualCuWithholdingReturnHandler;
use Modules\WithholdingRecipients\Http\Controllers\Concerns\ResolvesWithholdingContext;
use Modules\WithholdingRecipients\Http\Requests\AnnualReturnIndexRequest;
use Modules\WithholdingRecipients\Http\Requests\GenerateAnnualReturnRequest;
use Modules\WithholdingRecipients\Http\Requests\YearExportRequest;
use Modules\WithholdingRecipients\Http\Resources\CuRecordResource;
use Modules\WithholdingRecipients\Models\CuAutonomousRecord;
use Modules\WithholdingRecipients\Services\AnnualReturnService;
use OpenApi\Attributes as OA;

/**
 * Annual Certificazione Unica (and Modello 770 data) of the Italian recipients, AVS / source tax statements of the Swiss ones.
 */
final class AnnualReturnController extends Controller
{
    use ApiResponse;
    use ResolvesWithholdingContext;

    public function __construct(
        private readonly GenerateAnnualCuWithholdingReturnHandler $generate,
        private readonly AnnualReturnService $returns,
    ) {}

    #[OA\Get(path: '/api/v1/withholding-recipients/annual-returns', operationId: 'withholdingRecipientsAnnualReturns', summary: 'Annual CU / AVS records of a tax year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'Records')])]
    public function index(AnnualReturnIndexRequest $request): JsonResponse
    {
        $year = (int) ($request->validated('tax_year') ?? CarbonImmutable::today()->year);

        return $this->successResponse(CuRecordResource::collection(CuAutonomousRecord::query()->with('profile')->where('tax_year', $year)->orderBy('return_type')->get()), 'OK', 200, ['tax_year' => $year]);
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/annual-returns/generate', operationId: 'withholdingRecipientsAnnualReturnsGenerate', summary: 'Generate the annual CU / AVS records of a tax year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 201, description: 'Records generated')])]
    public function generate(GenerateAnnualReturnRequest $request): JsonResponse
    {
        $records = $this->generate->handle(new GenerateAnnualCuWithholdingReturnCommand((int) $request->validated('tax_year'), $request->validated('recipient_profile_id'), $this->userId($request)));

        return $this->successResponse(CuRecordResource::collection($records->load('profile')), $this->message('returns_generated'), 201);
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/annual-returns/export', operationId: 'withholdingRecipientsAnnualReturnsExport', summary: 'Annual CU / AVS records of a tax year as CSV', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'CSV file')])]
    public function export(YearExportRequest $request): Response
    {
        Gate::authorize('settle', CuAutonomousRecord::class);
        $year = (int) $request->validated('tax_year');

        return response($this->returns->csv($year), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="cu-'.$year.'.csv"']);
    }
}
