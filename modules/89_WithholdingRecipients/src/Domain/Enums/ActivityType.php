<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Enums;

enum ActivityType: string
{
    case Artist = 'artist';
    case Athlete = 'athlete';
    case Speaker = 'speaker';
}
