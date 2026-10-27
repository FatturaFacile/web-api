<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ImportBankPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ImportPaymentsRequest;
use App\Http\Requests\Sales\MatchImportLineRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\PaymentImport;
use App\Models\PaymentImportLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class PaymentImportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ImportBankPayments $importPayments) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $imports = PaymentImport::query()->latest()->paginate($perPage);

        return $this->successResponse(
            data: CatalogResource::collection($imports),
            message: __('sales.messages.payment_imports_list'),
            meta: ['current_page' => $imports->currentPage(), 'last_page' => $imports->lastPage(), 'per_page' => $imports->perPage(), 'total' => $imports->total()]
        );
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PaymentImport::with('lines')->findOrFail($id)), __('sales.messages.payment_import_retrieved'));
    }

    /**
     * Upload an e-banking camt.053/camt.054 file and auto-match its credits to open invoices.
     */
    public function store(ImportPaymentsRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            $import = $this->importPayments->execute((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse(
                title: __('bank.invalid_file_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(new CatalogResource($import), __('sales.messages.payments_imported'), Response::HTTP_CREATED);
    }

    public function matchLine(MatchImportLineRequest $request, string $id, string $lineId): JsonResponse
    {
        $line = PaymentImportLine::where('payment_import_id', $id)->findOrFail($lineId);
        $line = $this->importPayments->matchManually($line, Invoice::findOrFail($request->validated('invoice_id')));

        return $this->successResponse(new CatalogResource($line), __('sales.messages.line_matched'));
    }
}
