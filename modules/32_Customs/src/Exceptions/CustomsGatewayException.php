<?php

declare(strict_types=1);

namespace Modules\Customs\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * The customs channel refused or cannot do the operation, including a real channel that is not configured.
 */
final class CustomsGatewayException extends CustomsException
{
    protected function status(): int
    {
        return Response::HTTP_BAD_GATEWAY;
    }
}
