<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\OverrideInvoiceMatchRequest;
use Modules\Procurement\Http\Requests\PartialInvoiceResolutionRequest;
use Modules\Procurement\Http\Requests\RematchInvoiceRequest;
use Modules\Procurement\Http\Requests\RequestCreditNoteRequest;
use Modules\Procurement\Http\Resources\InvoiceMatchRunResource;
use Modules\Procurement\Models\InvoiceMatchingRun;
use Modules\Procurement\Services\InvoiceMatchingQueryService;
use Modules\Procurement\Services\InvoiceMatchResolutionService;
use OpenApi\Attributes as OA;

/**
 * Exception resolution of blocked invoices: credit note request, re-match, partial resolution and the supervisor / CFO
 * override (limited to the accounts payable approval permission by the route).
 */
final class InvoiceMatchResolutionController extends Controller
{
    use ApiResponse;
    use ProcurementControllerHelpers;

    public function __construct(
        private readonly InvoiceMatchResolutionService $resolutions,
        private readonly InvoiceMatchingQueryService $queries,
    ) {}

    #[OA\Post(path: '/api/v1/procurement/invoice-matching/runs/{id}/request-credit-note', operationId: 'requestInvoiceMatchingCreditNote', summary: 'Ask the supplier for a credit note', description: 'blocked_discrepancy -> pending_credit_note; the invoice stays blocked.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The run'), new OA\Response(response: 422, description: 'The run does not block the invoice')])]
    public function requestCreditNote(RequestCreditNoteRequest $request, string $id): JsonResponse
    {
        $run = $this->resolutions->requestCreditNote(
            $this->run($id),
            $this->actorId($request),
            (string) $request->validated('reason'),
            $request->validated('expected_credit_amount') !== null ? (string) $request->validated('expected_credit_amount') : null,
        );

        return $this->respond($run, 'credit_note_requested');
    }

    #[OA\Post(path: '/api/v1/procurement/invoice-matching/runs/{id}/rematch', operationId: 'rematchInvoiceMatchingRun', summary: 'Match the invoice again with the current order and receipt data', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The new current run')])]
    public function rematch(RematchInvoiceRequest $request, string $id): JsonResponse
    {
        $run = $this->resolutions->rematch($this->run($id), $this->actorId($request), $request->validated('reason'));

        return $this->respond($run, 'rematched');
    }

    #[OA\Post(path: '/api/v1/procurement/invoice-matching/runs/{id}/partial-resolution', operationId: 'resolveInvoiceMatchingPartially', summary: 'Accept some of the discrepant lines', description: 'The invoice is released (resolved) when no discrepant line is left.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The run'), new OA\Response(response: 422, description: 'A line is not a discrepancy of the run')])]
    public function partialResolution(PartialInvoiceResolutionRequest $request, string $id): JsonResponse
    {
        $run = $this->resolutions->resolvePartially($this->run($id), $this->actorId($request), (string) $request->validated('reason'), array_values($request->validated('line_ids')));

        return $this->respond($run, 'partially_resolved');
    }

    #[OA\Post(path: '/api/v1/procurement/invoice-matching/runs/{id}/override', operationId: 'overrideInvoiceMatchingRun', summary: 'Supervisor / CFO override of the payment block', description: 'Accepts every discrepancy and releases the invoice into the payment schedule. Needs the accounts_payable_approval permission and a reason; the action is audited.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The released run'), new OA\Response(response: 403, description: 'Missing the approval permission')])]
    public function override(OverrideInvoiceMatchRequest $request, string $id): JsonResponse
    {
        $run = $this->resolutions->override($this->run($id), $this->actorId($request), (string) $request->validated('reason'));

        return $this->respond($run, 'overridden');
    }

    private function run(string $id): InvoiceMatchingRun
    {
        return InvoiceMatchingRun::query()->findOrFail($id);
    }

    private function respond(InvoiceMatchingRun $run, string $message): JsonResponse
    {
        return $this->successResponse(new InvoiceMatchRunResource($this->queries->find((string) $run->id)), __("procurement.invoice_matching.messages.{$message}"));
    }
}
