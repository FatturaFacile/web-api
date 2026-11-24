<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\AccountsPayable\Http\Controllers\Concerns\ResolvesAccountsPayableContext;
use Modules\AccountsPayable\Http\Requests\PayablesAgingRequest;
use Modules\AccountsPayable\Services\PayablesAgingExporter;
use Modules\AccountsPayable\Services\PayablesAgingService;
use OpenApi\Attributes as OA;

/**
 * The payables aging report (aging fornitori) and its Excel and PDF exports.
 */
final class PayablesAgingController extends Controller
{
    use ApiResponse;
    use ResolvesAccountsPayableContext;

    public function __construct(
        private readonly PayablesAgingService $aging,
        private readonly PayablesAgingExporter $exporter,
    ) {}

    #[OA\Get(path: '/api/v1/accounts-payable/aging', operationId: 'getApPayablesAging', summary: 'Payables aging by supplier', description: 'Buckets current, 1_30, 31_60, 61_90 and over_90 days past due, per supplier and in total, with the credit notes and the held amounts; detail=true adds the lines of each supplier.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The aging report')])]
    public function index(PayablesAgingRequest $request): JsonResponse
    {
        $report = $this->aging->report($this->tenantId(), $request->asOf(), $request->supplierId(), $request->currency(), $request->boolean('detail', true));

        return $this->successResponse($report, __('accounts_payable.messages.aging_retrieved'));
    }

    #[OA\Get(path: '/api/v1/accounts-payable/aging/export/xlsx', operationId: 'exportApPayablesAgingXlsx', summary: 'Payables aging as an Excel workbook', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The workbook', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))])]
    public function xlsx(PayablesAgingRequest $request): Response
    {
        $tenantId = $this->tenantId();
        $report = $this->aging->report($tenantId, $request->asOf(), $request->supplierId(), $request->currency());

        return $this->download($this->exporter->xlsx($report, $this->locale($request), $tenantId), 'payables-aging-'.$report['as_of'].'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[OA\Get(path: '/api/v1/accounts-payable/aging/export/pdf', operationId: 'exportApPayablesAgingPdf', summary: 'Payables aging as a PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The PDF', content: new OA\MediaType(mediaType: 'application/pdf'))])]
    public function pdf(PayablesAgingRequest $request): Response
    {
        $tenantId = $this->tenantId();
        $report = $this->aging->report($tenantId, $request->asOf(), $request->supplierId(), $request->currency());

        return $this->download($this->exporter->pdf($report, $this->locale($request), $tenantId), 'payables-aging-'.$report['as_of'].'.pdf', 'application/pdf');
    }

    private function locale(PayablesAgingRequest $request): string
    {
        return (string) ($request->input('locale') ?: app()->getLocale());
    }
}
