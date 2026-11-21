<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\AiCognitive\Application\Commands\ApproveAiSuggestionCommand;
use Modules\AiCognitive\Application\Commands\ExecuteCognitivePipelineCommand;
use Modules\AiCognitive\Application\Commands\RejectAiSuggestionCommand;
use Modules\AiCognitive\Application\Handlers\ApproveAiSuggestionHandler;
use Modules\AiCognitive\Application\Handlers\ExecuteCognitivePipelineHandler;
use Modules\AiCognitive\Application\Handlers\GetAiWorkbenchQueueHandler;
use Modules\AiCognitive\Application\Handlers\RejectAiSuggestionHandler;
use Modules\AiCognitive\Application\Queries\GetAiWorkbenchQueueQuery;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveNotFoundException;
use Modules\AiCognitive\Infrastructure\Services\AccountingCopilotService;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\AiCognitive\Presentation\Http\Requests\AiListRequest;
use Modules\AiCognitive\Presentation\Http\Requests\ApproveAiSuggestionRequest;
use Modules\AiCognitive\Presentation\Http\Requests\ExtractCognitiveRequest;
use Modules\AiCognitive\Presentation\Http\Requests\RejectAiSuggestionRequest;
use Modules\AiCognitive\Presentation\Http\Requests\RunAiJobRequest;
use Modules\AiCognitive\Presentation\Http\Requests\SuggestCodingRequest;
use Modules\AiCognitive\Presentation\Http\Resources\AiSuggestionResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class AiSuggestionController extends AiApiController
{
    #[OA\Get(path: '/api/v1/ai/suggestions', operationId: 'listAiSuggestions', description: 'The Cognitive Review Workbench queue: open suggestions (pending and failed), the most urgent first, with the count of open items per kind. Filter by status, kind and priority.', summary: 'Workbench queue', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'The queue', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(AiListRequest $request, GetAiWorkbenchQueueHandler $handler): JsonResponse
    {
        $data = $request->validated();
        $result = $handler->handle(new GetAiWorkbenchQueueQuery($this->tenantId(), $data['status'] ?? null, $data['kind'] ?? null, $data['priority'] ?? null, (int) ($data['per_page'] ?? 25)));

        return $this->successResponse(
            AiSuggestionResource::collection($result['items'])->resolve(),
            'AI suggestions',
            Response::HTTP_OK,
            $this->pageMeta($result['items']) + ['open_by_kind' => $result['open_by_kind']],
        );
    }

    #[OA\Get(path: '/api/v1/ai/suggestions/{id}', operationId: 'showAiSuggestion', description: 'One suggestion with the source text, the proposed ERP action, the field confidences and the reasoning.', summary: 'Suggestion detail', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The suggestion', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Unknown suggestion')])]
    public function show(string $id, GetAiWorkbenchQueueHandler $handler): JsonResponse
    {
        // Asked by id the queue handler returns the suggestion whatever its status, so decided items stay readable.
        $found = $handler->handle(new GetAiWorkbenchQueueQuery($this->tenantId(), suggestionId: $id, perPage: 1))['items']->items()[0] ?? null;

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new AiSuggestionResource($found ?? throw AiCognitiveNotFoundException::suggestion($id))));
    }

    #[OA\Post(path: '/api/v1/ai/suggestions/extract', operationId: 'extractAiSuggestion', description: 'Reads an unstructured text (customer email, RFQ, supplier delivery note, support message) into a draft sales quote, purchase order, support ticket or contact with field level confidences. The draft is executed at once when the tenant policy allows it, otherwise it enters the review queue.', summary: 'Extract a draft ERP entity from text', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 201, description: 'The suggestion', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function extract(ExtractCognitiveRequest $request, ExecuteCognitivePipelineHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new AiSuggestionResource($handler->handle(new ExecuteCognitivePipelineCommand(
            $this->tenantId(),
            $this->userId(),
            SuggestionKind::from($data['kind']),
            $data['source_type'],
            $data['text'],
            $data['source_reference'] ?? null,
            $data['locale'] ?? app()->getLocale(),
        ))), 'Suggestion created', Response::HTTP_CREATED));
    }

    #[OA\Post(path: '/api/v1/ai/suggestions/{id}/approve', operationId: 'approveAiSuggestion', description: 'Approves a suggestion as proposed, or with the corrected payload (Edit and Approve). The ERP record is created through the same actions the rest of the ERP uses; an incomplete proposal is refused with a readable reason and stays in the queue.', summary: 'Approve a suggestion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 409, description: 'Already decided'), new OA\Response(response: 422, description: 'Incomplete proposal')])]
    public function approve(string $id, ApproveAiSuggestionRequest $request, ApproveAiSuggestionHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new AiSuggestionResource($handler->handle(new ApproveAiSuggestionCommand(
            $this->tenantId(),
            $this->userId(),
            $id,
            isset($data['edited_payload']) ? (array) $data['edited_payload'] : null,
            $data['feedback'] ?? null,
        ))), 'Suggestion approved'));
    }

    #[OA\Post(path: '/api/v1/ai/suggestions/{id}/reject', operationId: 'rejectAiSuggestion', description: 'Rejects a suggestion with a feedback reason, which is kept for the feedback loop and the audit trail.', summary: 'Reject a suggestion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 409, description: 'Already decided')])]
    public function reject(string $id, RejectAiSuggestionRequest $request, RejectAiSuggestionHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new AiSuggestionResource($handler->handle(new RejectAiSuggestionCommand($this->tenantId(), $this->userId(), $id, $data['reason']))), 'Suggestion rejected'));
    }

    #[OA\Post(path: '/api/v1/ai/accounting/coding', operationId: 'suggestAiAccountingCoding', description: 'Proposes the expense account, the VAT code (Swiss MWST or Italian IVA) and the cost center for an unmapped bank transaction or an expense receipt, only from the lists of the company.', summary: 'Suggest account, VAT code and cost center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 201, description: 'The suggestion', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function coding(SuggestCodingRequest $request, AccountingCopilotService $service, AiTenantRunner $runner): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new AiSuggestionResource($runner->run($this->tenantId(), fn () => $service->suggestCoding(
            $this->tenantId(),
            $this->userId(),
            $data['description'],
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['counterparty'] ?? null,
            $data['source_type'],
            $data['source_reference'] ?? null,
            app()->getLocale(),
        ))), 'Suggestion created', Response::HTTP_CREATED));
    }

    #[OA\Post(path: '/api/v1/ai/accounting/reconciliation', operationId: 'suggestAiReconciliation', description: 'Proposes matches between the unmatched bank transactions and the open invoices (exact, partial and grouped payments) by amount, payment reference, name similarity and semantic similarity.', summary: 'Suggest bank reconciliation matches', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'The suggestions', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function reconciliation(RunAiJobRequest $request, AccountingCopilotService $service, AiTenantRunner $runner): JsonResponse
    {
        $limit = (int) ($request->validated()['limit'] ?? 200);
        $found = $runner->run($this->tenantId(), fn (): array => $service->suggestReconciliation($this->tenantId(), $this->userId(), $limit));

        return $this->successResponse(AiSuggestionResource::collection(collect($found))->resolve(), count($found).' matches proposed');
    }
}
