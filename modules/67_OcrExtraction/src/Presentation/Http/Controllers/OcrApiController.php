<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared helpers of the OCR extraction controllers.
 */
abstract class OcrApiController extends Controller
{
    use ApiResponse;

    public function __construct(protected readonly TenantContext $tenantContext) {}

    protected function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    protected function userId(): ?string
    {
        $id = auth()->id();

        return $id !== null ? (string) $id : null;
    }

    /**
     * Runs an action and maps the extraction exceptions to problem details.
     *
     * @param  Closure(): JsonResponse  $action
     */
    protected function guarded(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (OcrExtractionNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (OcrExtractionException $exception) {
            return $this->problemDetailsResponse('OCR Extraction Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
