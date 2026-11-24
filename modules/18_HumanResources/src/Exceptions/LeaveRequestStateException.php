<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * The leave request is not in a state that allows the action (already decided, or decided by its workflow): 409.
 */
final class LeaveRequestStateException extends HumanResourcesException
{
    protected function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
