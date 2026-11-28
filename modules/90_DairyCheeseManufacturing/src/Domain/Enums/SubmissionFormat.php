<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Mod 298 — SubmissionFormat. Labels are the frontend keys DAIRY.ENUM.<ENUM>.<VALUE> (see the lookups).
 */
enum SubmissionFormat: string
{
    use LabelledEnum;

    case Xml = 'xml';
    case Csv = 'csv';
}
