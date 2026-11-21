<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Quoting\Application\Commands\ConvertQuoteToSalesOrderCommand;
use Modules\Quoting\Application\Commands\CreateNewQuoteRevisionCommand;
use Modules\Quoting\Application\Commands\CreateQuoteCommand;
use Modules\Quoting\Application\Commands\IssueQuoteRevisionCommand;
use Modules\Quoting\Application\Commands\ReorderQuoteLinesCommand;
use Modules\Quoting\Application\Commands\UpdateDraftQuoteCommand;
use Modules\Quoting\Application\Handlers\CompareQuoteRevisionsHandler;
use Modules\Quoting\Application\Handlers\ConvertQuoteToSalesOrderHandler;
use Modules\Quoting\Application\Handlers\CreateNewQuoteRevisionHandler;
use Modules\Quoting\Application\Handlers\CreateQuoteHandler;
use Modules\Quoting\Application\Handlers\GetQuoteDetailsHandler;
use Modules\Quoting\Application\Handlers\IssueQuoteRevisionHandler;
use Modules\Quoting\Application\Handlers\ListQuotesHandler;
use Modules\Quoting\Application\Handlers\ReorderQuoteLinesHandler;
use Modules\Quoting\Application\Handlers\UpdateDraftQuoteHandler;
use Modules\Quoting\Application\Queries\CompareQuoteRevisionsQuery;
use Modules\Quoting\Application\Queries\GetQuoteDetailsQuery;
use Modules\Quoting\Application\Queries\ListQuotesQuery;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Presentation\Http\Requests\IssueQuoteRequest;
use Modules\Quoting\Presentation\Http\Requests\ReorderQuoteRequest;
use Modules\Quoting\Presentation\Http\Requests\SaveQuoteRequest;
use Modules\Quoting\Presentation\Http\Resources\CpqQuoteResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class QuoteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/cpq/quotes', operationId: 'listCpqQuotes', summary: 'List the interactive quotes (one row per revision)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], responses: [new OA\Response(response: 200, description: 'A page of quotes')])]
    public function index(Request $request, ListQuotesHandler $handler): JsonResponse
    {
        $page = $handler->handle(new ListQuotesQuery(
            $request->query('status') === null ? null : (string) $request->query('status'),
            $request->query('contact_id') === null ? null : (string) $request->query('contact_id'),
            $request->query('sales_rep_id') === null ? null : (string) $request->query('sales_rep_id'),
            $request->query('expiring_before') === null ? null : (string) $request->query('expiring_before'),
            min(100, max(1, (int) $request->query('per_page', 25))),
        ));

        return $this->successResponse(['items' => CpqQuoteResource::collection($page->items())->resolve(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage()]);
    }

    #[OA\Post(path: '/api/v1/cpq/quotes', operationId: 'createCpqQuote', summary: 'Create a draft quote (revision 01) with sections and lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], responses: [new OA\Response(response: 201, description: 'The draft')])]
    public function store(SaveQuoteRequest $request, CreateQuoteHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            (new CpqQuoteResource($handler->handle(new CreateQuoteCommand((string) $this->tenantContext->getTenantId(), $this->userId(), $request->validated()))))->resolve(),
            statusCode: Response::HTTP_CREATED,
        ));
    }

    #[OA\Get(path: '/api/v1/cpq/quotes/{id}', operationId: 'getCpqQuote', summary: 'A revision with sections, lines, audit events and signature envelopes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The quote')])]
    public function show(string $id, GetQuoteDetailsHandler $handler): JsonResponse
    {
        return $this->successResponse((new CpqQuoteResource($handler->handle(new GetQuoteDetailsQuery($id))))->resolve());
    }

    #[OA\Put(path: '/api/v1/cpq/quotes/{id}', operationId: 'updateCpqQuote', summary: 'Update a draft revision (a frozen one answers 422)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The updated draft'), new OA\Response(response: 422, description: 'The revision is frozen')])]
    public function update(string $id, SaveQuoteRequest $request, UpdateDraftQuoteHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse((new CpqQuoteResource($handler->handle(new UpdateDraftQuoteCommand($id, $this->userId(), $request->validated()))))->resolve()));
    }

    #[OA\Post(path: '/api/v1/cpq/quotes/{id}/reorder', operationId: 'reorderCpqQuoteLines', summary: 'Reorder sections and lines (drag and drop), also across sections', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The reordered draft')])]
    public function reorder(string $id, ReorderQuoteRequest $request, ReorderQuoteLinesHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse((new CpqQuoteResource($handler->handle(new ReorderQuoteLinesCommand(
            $id, array_values((array) $request->validated('section_order')), array_values((array) $request->validated('line_order')),
        ))))->resolve()));
    }

    #[OA\Post(path: '/api/v1/cpq/quotes/{id}/issue', operationId: 'issueCpqQuoteRevision', summary: 'Freeze and send the revision; returns the portal token and optional PIN once. A margin below the guardrail parks it in pending_approval', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Sent, or pending approval when the margin guardrail trips')])]
    public function issue(string $id, IssueQuoteRequest $request, IssueQuoteRevisionHandler $handler): JsonResponse
    {
        return $this->issueQuote($id, $request, $handler, false);
    }

    #[OA\Post(path: '/api/v1/cpq/quotes/{id}/approve-and-issue', operationId: 'approveAndIssueCpqQuoteRevision', summary: 'Manager override of the margin guardrail: approves and issues the revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Sent')])]
    public function approveAndIssue(string $id, IssueQuoteRequest $request, IssueQuoteRevisionHandler $handler): JsonResponse
    {
        return $this->issueQuote($id, $request, $handler, true);
    }

    private function issueQuote(string $id, IssueQuoteRequest $request, IssueQuoteRevisionHandler $handler, bool $override): JsonResponse
    {
        return $this->guarded(function () use ($id, $request, $handler, $override): JsonResponse {
            $result = $handler->handle(new IssueQuoteRevisionCommand($id, $this->userId(), $request->integer('validity_days') ?: null, $request->boolean('use_pin'), $override));

            return $this->successResponse([
                'quote' => (new CpqQuoteResource($result['quote']))->resolve(),
                'portal_token' => $result['portal_token'],
                'portal_url' => $result['portal_token'] === null ? null : rtrim((string) config('quoting.portal_url'), '/').'/'.$result['portal_token'],
                'pin' => $result['pin'],
            ]);
        });
    }

    #[OA\Post(path: '/api/v1/cpq/quotes/{id}/revisions', operationId: 'createCpqQuoteRevision', summary: 'Clone the latest frozen revision into a new draft (.XX+1)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 201, description: 'The new draft revision')])]
    public function createRevision(string $id, CreateNewQuoteRevisionHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            (new CpqQuoteResource($handler->handle(new CreateNewQuoteRevisionCommand($id, $this->userId()))))->resolve(),
            statusCode: Response::HTTP_CREATED,
        ));
    }

    #[OA\Get(path: '/api/v1/cpq/quotes/{id}/compare/{otherId}', operationId: 'compareCpqQuoteRevisions', summary: 'Structural diff of two revisions of the same quote', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'otherId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Added, removed and modified sections and lines, and the total variance')])]
    public function compare(string $id, string $otherId, CompareQuoteRevisionsHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new CompareQuoteRevisionsQuery($id, $otherId))));
    }

    #[OA\Post(path: '/api/v1/cpq/quotes/{id}/convert-to-order', operationId: 'convertCpqQuoteToSalesOrder', summary: '1-click conversion of an accepted quote into a sales order (idempotent)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The sales order (the same one on a repeated call)')])]
    public function convertToOrder(string $id, ConvertQuoteToSalesOrderHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($id, $handler): JsonResponse {
            $order = $handler->handle(new ConvertQuoteToSalesOrderCommand($id, $this->userId()));

            return $this->successResponse(['sales_order_id' => (string) $order->id, 'order_number' => $order->getAttribute('order_number')]);
        });
    }

    #[OA\Get(path: '/api/v1/cpq/quotes/{id}/documents/{kind}', operationId: 'downloadCpqQuoteDocument', summary: 'Download the signed PDF proposal (kind=proposal) or the audit certificate (kind=certificate)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quoting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The PDF')])]
    public function document(string $id, string $kind): JsonResponse|StreamedResponse
    {
        $quote = CpqQuote::query()->findOrFail($id);
        $path = $kind === 'certificate' ? $quote->audit_certificate_path : ($kind === 'proposal' ? $quote->signed_document_path : null);
        $disk = Storage::disk((string) config('quoting.storage_disk', 'local'));

        if ($path === null || ! $disk->exists($path)) {
            return $this->problemDetailsResponse('Not Found', 'The document is not available.', Response::HTTP_NOT_FOUND);
        }

        return $disk->response($path, $quote->quote_number.'-'.$kind.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function userId(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (QuoteException $exception) {
            return $this->problemDetailsResponse('Quote Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
