<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\CorporateConventions\Application\Commands\GenerateMonthlyPartnerSplitBillingInvoiceCommand;
use Modules\CorporateConventions\Application\Commands\GenerateMonthlyPartnerSplitBillingInvoiceHandler;
use Modules\CorporateConventions\Http\Controllers\Concerns\InteractsWithConventions;
use Modules\CorporateConventions\Http\Requests\ConsolidationRequest;
use Modules\CorporateConventions\Http\Requests\ListFiltersRequest;
use Modules\CorporateConventions\Http\Resources\SplitBillingResource;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\SplitBillingConsolidationService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Split billing between employee and employer: the recorded purchases, the pending employer quotas and the consolidated B2B invoice.
 */
final class SplitBillingController extends Controller
{
    use ApiResponse;
    use InteractsWithConventions;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly SplitBillingConsolidationService $consolidation) {}

    #[OA\Get(path: '/api/v1/sales/split-billings', operationId: 'splitBillingsList', summary: 'List the split billings of the convention purchases', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Split billings, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ConventionSplitBilling::class);

        $page = ConventionSplitBilling::query()
            ->with(['convention:id,name', 'enrollment:id,full_name'])
            ->when($request->input('convention_id'), fn (Builder $query, string $id) => $query->where('convention_id', $id))
            ->when($request->input('status'), fn (Builder $query, string $status) => $query->where('billing_status', $status))
            ->when($request->input('from'), fn (Builder $query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($request->input('to'), fn (Builder $query, string $date) => $query->whereDate('transaction_date', '<=', $date))
            ->orderByDesc('transaction_date')->orderByDesc('created_at')
            ->paginate($this->perPage());

        return $this->paged($page, SplitBillingResource::class);
    }

    #[OA\Get(path: '/api/v1/sales/split-billings/pending', operationId: 'splitBillingsPending', summary: 'Pending employer quotas, by convention', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Pending quotas')])]
    public function pending(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ConventionSplitBilling::class);

        return $this->successResponse($this->consolidation->pending($request->input('convention_id')), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/sales/split-billings/preview', operationId: 'splitBillingsPreview', summary: 'Preview the consolidated invoice of a convention and period with the beneficiary schedule', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Preview with the nominal or anonymized schedule')])]
    public function preview(ConsolidationRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ConventionSplitBilling::class);
        $convention = CorporatePartnerConvention::query()->findOrFail($request->validated('convention_id'));
        [$from, $to] = $request->bounds();

        return $this->successResponse($this->consolidation->preview($convention, $from, $to, $request->has('anonymize') && $request->input('anonymize') !== null ? $request->boolean('anonymize') : null), $this->message('previewed'));
    }

    #[OA\Post(path: '/api/v1/sales/split-billings/generate-invoice', operationId: 'splitBillingsGenerateInvoice', summary: 'Generate the consolidated B2B invoice of the employer quotas of a convention and period', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 201, description: 'Invoice created and the quotas marked as invoiced')])]
    public function generateInvoice(ConsolidationRequest $request, GenerateMonthlyPartnerSplitBillingInvoiceHandler $handler): JsonResponse
    {
        Gate::authorize('bill', ConventionSplitBilling::class);
        [$from, $to] = $request->bounds();
        $result = $handler->handle(new GenerateMonthlyPartnerSplitBillingInvoiceCommand(
            (string) $request->validated('convention_id'),
            $from,
            $to,
            $request->has('anonymize') && $request->input('anonymize') !== null ? $request->boolean('anonymize') : null,
            $this->userId(),
        ));

        return $this->successResponse([
            'invoice_id' => $result['invoice']->id,
            'invoice_number' => $result['invoice']->invoice_number,
            'total_gross' => (string) $result['invoice']->total_gross,
            'rows_count' => $result['rows_count'],
            'total_employer_quota' => $result['total_employer_quota'],
            'attachment_url' => '/api/v1/sales/split-billings/invoices/'.$result['invoice']->id.'/attachment',
        ], $this->message('invoiced'), 201);
    }

    #[OA\Get(path: '/api/v1/sales/split-billings/invoices/{invoiceId}/attachment', operationId: 'splitBillingsAttachment', summary: 'Beneficiary schedule (CSV) attached to a consolidated invoice', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'invoiceId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'CSV file')])]
    public function attachment(string $invoiceId): StreamedResponse
    {
        Gate::authorize('viewAny', ConventionSplitBilling::class);
        $path = $this->consolidation->attachmentPath($invoiceId);

        return Storage::disk((string) config('corporate_conventions.storage.disk'))->download($path, 'beneficiary-schedule-'.$invoiceId.'.csv', ['Content-Type' => 'text/csv']);
    }
}
