<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * The record is not in a state that allows the action (a closed goal cycle, a finished checklist): 409.
 */
final class HrRecordStateException extends HumanResourcesException
{
    protected function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
