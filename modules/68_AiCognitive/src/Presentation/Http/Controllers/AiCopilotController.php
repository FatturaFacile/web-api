<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\AiCognitive\Application\Commands\RebuildTenantVectorIndexCommand;
use Modules\AiCognitive\Application\Handlers\QueryErpCopilotHandler;
use Modules\AiCognitive\Application\Handlers\RebuildTenantVectorIndexHandler;
use Modules\AiCognitive\Application\Queries\QueryErpCopilotQuery;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\AiCognitive\Infrastructure\Services\DraftGenerationService;
use Modules\AiCognitive\Presentation\Http\Requests\CopilotQueryRequest;
use Modules\AiCognitive\Presentation\Http\Requests\GenerateDraftRequest;
use Modules\AiCognitive\Presentation\Http\Requests\RunAiJobRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class AiCopilotController extends AiApiController
{
    #[OA\Post(path: '/api/v1/ai/copilot/query', operationId: 'queryAiCopilot', description: 'Asks the ERP copilot. An analytics question ("unpaid QR-bills over CHF 5,000 overdue by more than 15 days") becomes a governed, parameterized, tenant-scoped query: the model never writes SQL. A knowledge question is answered from the tenant index with source citations.', summary: 'Ask the ERP copilot', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'The answer', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function query(CopilotQueryRequest $request, QueryErpCopilotHandler $handler): JsonResponse
    {
        $data = $request->validated();
        /** @var User $user */
        $user = $request->user();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new QueryErpCopilotQuery($user, $this->tenantId(), $data['question'], $data['mode'] ?? 'auto', app()->getLocale()))));
    }

    #[OA\Post(path: '/api/v1/ai/copilot/drafts', operationId: 'generateAiDraft', description: 'Drafts a customer email, a payment reminder or a quote cover letter in it-CH, de-CH, fr-CH, en or it-IT from the facts given. Nothing is sent: the user reviews the text.', summary: 'Draft a multilingual message', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'Subject and body', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function draft(GenerateDraftRequest $request, DraftGenerationService $service, AiTenantRunner $runner): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            $runner->run($this->tenantId(), fn (): array => $service->draft($this->tenantId(), $this->userId(), $data['purpose'], $data['language'], (array) $data['facts'], $data['instructions'] ?? null)),
        ));
    }

    #[OA\Post(path: '/api/v1/ai/copilot/index/rebuild', operationId: 'rebuildAiVectorIndex', description: 'Queues the batch embedding of the tenant knowledge index (customers, invoices, quotes, tickets and the Swiss and Italian fiscal rules).', summary: 'Rebuild the tenant vector index', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function rebuildIndex(RunAiJobRequest $request, RebuildTenantVectorIndexHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new RebuildTenantVectorIndexCommand($this->tenantId(), $this->userId())), 'Index rebuild queued', Response::HTTP_ACCEPTED);
    }
}
