<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Enums;

enum FieldVisibility: string
{
    case Visible = 'visible';
    case Masked = 'masked';
    case Hidden = 'hidden';
}
