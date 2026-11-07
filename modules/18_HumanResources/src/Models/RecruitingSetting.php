<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

/**
 * The recruiting choices of a company.
 */
final class RecruitingSetting extends CandidateRecord
{
    /** A starting choice for the company to confirm, not a legal term: the law sets none. */
    public const int DefaultRetentionDays = 180;

    protected $table = 'hr_recruiting_settings';

    protected function casts(): array
    {
        return ['retention_days' => 'integer'];
    }
}
