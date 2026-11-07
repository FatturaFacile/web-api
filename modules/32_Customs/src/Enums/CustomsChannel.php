<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

/**
 * The way a company talks to the customs system.
 */
enum CustomsChannel: string
{
    case Local = 'local';
    case Edec = 'edec';
}
