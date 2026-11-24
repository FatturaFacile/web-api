<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\RecruitingSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The recruiting choices of a company.
 */
#[UseFactory(RecruitingSettingFactory::class)]
final class RecruitingSetting extends CandidateRecord
{
    /** @use HasFactory<RecruitingSettingFactory> */
    use HasFactory;

    /** A starting choice for the company to confirm, not a legal term: the law sets none. */
    public const int DefaultRetentionDays = 180;

    protected $table = 'hr_recruiting_settings';

    protected function casts(): array
    {
        return ['retention_days' => 'integer'];
    }
}
