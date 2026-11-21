<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveNotFoundException;
use Modules\AiCognitive\Domain\Exceptions\AiPolicyViolationException;
use Modules\AiCognitive\Domain\Exceptions\AiProviderUnavailableException;
use Modules\AiCognitive\Domain\Exceptions\AiQuotaExceededException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared helpers of the AI layer controllers: the tenant, the user and the mapping of the layer exceptions to problem details.
 */
abstract class AiApiController extends Controller
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
     * @param  Closure(): JsonResponse  $action
     */
    protected function guarded(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (AiCognitiveNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (AuthorizationException $exception) {
            return $this->problemDetailsResponse('Forbidden', $exception->getMessage() !== '' ? $exception->getMessage() : 'You may not read this data.', Response::HTTP_FORBIDDEN);
        } catch (AiQuotaExceededException $exception) {
            return $this->problemDetailsResponse('AI Quota Exceeded', $exception->getMessage(), Response::HTTP_TOO_MANY_REQUESTS);
        } catch (AiProviderUnavailableException $exception) {
            return $this->problemDetailsResponse('AI Provider Unavailable', $exception->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (AiPolicyViolationException $exception) {
            return $this->problemDetailsResponse('AI Policy Violation', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (AiCognitiveException $exception) {
            return $this->problemDetailsResponse('AI Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * @param  LengthAwarePaginator<int, *>  $page
     * @return array<string, int>
     */
    protected function pageMeta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
