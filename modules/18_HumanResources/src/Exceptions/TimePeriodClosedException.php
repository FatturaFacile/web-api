<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * The working time records of the month are closed: reopen the month (with a reason) to change them. Rendered as 409.
 */
final class TimePeriodClosedException extends HumanResourcesException
{
    /**
     * The refusal for a month.
     */
    public static function forMonth(int $year, int $month): self
    {
        return new self(sprintf('Le registrazioni di %02d/%d sono chiuse: riaprire il mese indicando il motivo per modificarle.', $month, $year));
    }

    protected function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
