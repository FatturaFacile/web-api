<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — SubmissionStatus. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum SubmissionStatus: string
{
    use LabelledEnum;

    case Generated = 'generated';
    case Submitted = 'submitted';
    case Failed = 'failed';
}
