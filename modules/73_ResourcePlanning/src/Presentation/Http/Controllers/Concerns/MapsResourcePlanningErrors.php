<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Controllers\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Modules\ResourcePlanning\Domain\Exceptions\ResourceNotFoundException;
use Modules\ResourcePlanning\Domain\Exceptions\ResourcePlanningException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maps the exceptions of the planner to problem details: a missing record answers 404, a broken rule 422 with the stable
 * code (and its parameters) in invalid_params so that every client can translate it.
 */
trait MapsResourcePlanningErrors
{
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
        } catch (ResourceNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND, invalidParams: [$exception->toIssue()]);
        } catch (ResourcePlanningException $exception) {
            return $this->problemDetailsResponse('Resource Planning Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, invalidParams: [$exception->toIssue()]);
        }
    }

    private function date(?string $value, CarbonImmutable $default): CarbonImmutable
    {
        return $value === null ? $default : CarbonImmutable::parse($value)->startOfDay();
    }
}
