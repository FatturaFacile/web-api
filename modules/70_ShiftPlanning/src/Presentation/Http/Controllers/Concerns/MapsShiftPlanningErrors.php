<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Controllers\Concerns;

use Closure;
use Illuminate\Http\JsonResponse;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftAccessDeniedException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftComplianceException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftConflictException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maps the exceptions of the planner to problem details. A compliance violation answers 422 with every finding (the rule
 * code, the translation key and the parameters) in invalid_params.
 */
trait MapsShiftPlanningErrors
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
        } catch (ShiftComplianceException $exception) {
            return $this->problemDetailsResponse(
                'Shift Compliance Violation',
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $exception->issues),
            );
        } catch (ShiftNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ShiftConflictException $exception) {
            return $this->problemDetailsResponse('Shift Conflict', $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (ShiftAccessDeniedException $exception) {
            return $this->problemDetailsResponse('Forbidden', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (ShiftPlanningException $exception) {
            return $this->problemDetailsResponse('Shift Planning Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
