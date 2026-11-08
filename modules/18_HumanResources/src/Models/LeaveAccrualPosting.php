<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\LeaveAccrualPostingFactory;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One accrual written on a leave balance for a period ("2026-03", or "2026-YR" for a yearly grant). The unique key
 * (balance, period) makes running the accrual twice for the same month a no-op.
 */
#[UseFactory(LeaveAccrualPostingFactory::class)]
final class LeaveAccrualPosting extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hr_leave_accrual_postings';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['hours' => 'decimal:2'];
    }
}
