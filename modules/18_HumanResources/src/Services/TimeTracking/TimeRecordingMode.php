<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * The working time recording modes allowed by the Swiss ordinance 1 on the Labour Act (OLL 1).
 */
enum TimeRecordingMode: string
{
    /** Full recording (art. 73 OLL 1): start and end of work, breaks of 30 minutes or more, daily and weekly duration. */
    case Detailed = 'Detailed';

    /** Simplified recording (art. 73b OLL 1): only the daily duration, plus start and end of night and Sunday work. */
    case Simplified = 'Simplified';

    /** Waiver (art. 73a OLL 1): collective agreement, great autonomy, salary above the threshold, individual written agreement. */
    case Waived = 'Waived';
}
