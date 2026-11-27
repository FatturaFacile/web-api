<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum RunStatus: string
{
    use EnumValues;

    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
