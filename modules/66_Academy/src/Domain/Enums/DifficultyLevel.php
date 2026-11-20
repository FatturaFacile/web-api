<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\Enums;

enum DifficultyLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
}
