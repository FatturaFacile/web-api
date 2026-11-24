<?php

declare(strict_types=1);

namespace Modules\Payroll\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * A closed run, or something that belongs to it, was about to change.
 */
final class PayrollRunClosedException extends PayrollException
{
    public static function forPeriod(int $year, int $month): self
    {
        return new self(sprintf("L'elaborazione %02d/%d è chiusa: riaprirla per ricalcolarla.", $month, $year));
    }

    protected function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
