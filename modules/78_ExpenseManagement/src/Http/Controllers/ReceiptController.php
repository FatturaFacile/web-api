<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Application\Commands\ScanReceiptOcrCommand;
use Modules\ExpenseManagement\Application\Commands\ScanReceiptOcrHandler;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\ScanReceiptRequest;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\Receipt;
use Modules\ExpenseManagement\Services\ReceiptService;
use OpenApi\Attributes as OA;

/**
 * Receipt upload with OCR and duplicate detection, and the file for the previewer.
 */
final class ReceiptController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Post(path: '/api/v1/expense-management/receipts/scan', operationId: 'expenseManagementScanReceipt', summary: 'Upload a receipt, extract its fields and check for duplicates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Receipt id, SHA-256, OCR fields with confidence and duplicate warnings')])]
    public function scan(ScanReceiptRequest $request, ScanReceiptOcrHandler $handler): JsonResponse
    {
        Gate::authorize('create', ExpenseReport::class);

        $result = $handler->handle(new ScanReceiptOcrCommand($request->file('file'), $request->input('ocr_text'), $this->tenantId(), $this->userId()));

        return $this->successResponse($result, $this->message('scanned'));
    }

    #[OA\Get(path: '/api/v1/expense-management/receipts/{id}/file', operationId: 'expenseManagementReceiptFile', summary: 'The receipt file, for the previewer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function file(string $id, ReceiptService $receipts): Response
    {
        Gate::authorize('viewAny', ExpenseReport::class);
        $receipt = Receipt::query()->findOrFail($id);

        return response($receipts->read($receipt), 200, [
            'Content-Type' => $receipt->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($receipt->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
