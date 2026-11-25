<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\Enums;

enum Severity: string
{
    case Warning = 'warning';
    case Blocking = 'blocking';
}
