<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\OcrExtraction\Application\Handlers\TenantOcrConfigurationHandler;
use Modules\OcrExtraction\Presentation\Http\Requests\UpdateOcrConfigurationRequest;
use OpenApi\Attributes as OA;

final class OcrConfigurationController extends OcrApiController
{
    #[OA\Get(path: '/api/v1/ocr-inbox/configuration', operationId: 'showOcrConfiguration', description: 'The OCR engine choice of the tenant. Secrets are never returned: only which settings are stored.', summary: 'OCR engine configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], responses: [new OA\Response(response: 200, description: 'The configuration', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function show(TenantOcrConfigurationHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->show($this->tenantId())));
    }

    #[OA\Put(path: '/api/v1/ocr-inbox/configuration', operationId: 'updateOcrConfiguration', description: 'Switches the primary and fallback OCR engine of the tenant (local Ollama, LayoutLMv3, Tesseract, or cloud Gemini, OpenAI, Azure Document Intelligence with the tenant API key) and the touchless posting threshold.', summary: 'Change the OCR engine configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], responses: [new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function update(UpdateOcrConfigurationRequest $request, TenantOcrConfigurationHandler $handler): JsonResponse
    {
        /** @var array{primary_provider: string, fallback_provider?: string|null, provider_settings?: array<string, array<string, mixed>>, auto_confirm_confidence_threshold?: float|int|string|null, auto_confirm_enabled?: bool|null} $data */
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->update($this->tenantId(), $data), 'OCR configuration saved'));
    }
}
