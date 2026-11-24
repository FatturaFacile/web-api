<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\DigitalPreservation\Domain\Exceptions\ConnectorException;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared response helpers of the preservation controllers.
 */
abstract class PreservationApiController extends Controller
{
    use ApiResponse;

    /**
     * @param  class-string<JsonResource>  $resource
     * @param  LengthAwarePaginator<int, Model>  $page
     * @param  array<string, mixed>  $meta
     */
    protected function page(LengthAwarePaginator $page, string $resource, array $meta = []): JsonResponse
    {
        return $this->successResponse(
            $resource::collection($page->getCollection())->resolve(),
            'OK',
            Response::HTTP_OK,
            ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()] + $meta,
        );
    }

    /**
     * Run an action and map the preservation exceptions to problem details.
     *
     * @param  Closure(): JsonResponse  $action
     */
    protected function guarded(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ConnectorException $exception) {
            return $this->problemDetailsResponse('Conservator Error', $exception->getMessage(), Response::HTTP_BAD_GATEWAY);
        } catch (PreservationException $exception) {
            return $this->problemDetailsResponse('Preservation Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
