<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Modules\Forecasting\Domain\Exceptions\ForecastingException;
use Modules\Forecasting\Domain\Exceptions\ForecastingNotFoundException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared helpers of the forecasting controllers.
 */
abstract class ForecastingApiController extends Controller
{
    use ApiResponse;

    public function __construct(protected readonly TenantContext $tenantContext) {}

    protected function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    /**
     * Runs an action and maps the forecasting exceptions to problem details.
     *
     * @param  Closure(): JsonResponse  $action
     */
    protected function guarded(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ForecastingNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ForecastingException $exception) {
            return $this->problemDetailsResponse('Forecasting Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
