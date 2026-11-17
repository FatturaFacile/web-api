<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

/**
 * The customs system a declaration is lodged in: Swiss e-dec and its successor Passar, or the Italian AIDA.
 */
enum CustomsRegime: string
{
    case Edec = 'edec';
    case Passar = 'passar';
    case Aida = 'aida';
}
