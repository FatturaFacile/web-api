<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An open position offered by the company.
 */
final class JobPosting extends BaseEnterpriseModel
{
    public const string StatusDraft = 'draft';

    public const string StatusOpen = 'open';

    public const string StatusClosed = 'closed';

    /** @var list<string> */
    public const array Statuses = [self::StatusDraft, self::StatusOpen, self::StatusClosed];

    protected $table = 'hr_job_postings';

    protected function casts(): array
    {
        return ['opens_on' => 'date:Y-m-d', 'closes_on' => 'date:Y-m-d'];
    }

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'job_posting_id');
    }
}
