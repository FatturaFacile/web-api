<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use App\Models\User;
use Database\Factories\ExportScheduleFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring export of a designed report. It runs with the permissions of its owner: when the owner loses access to
 * the dataset the run fails and says so, it never widens access.
 *
 * @property string $id
 * @property string $user_id
 * @property string $designed_report_id
 * @property string $frequency
 * @property ?int $day_of_week
 * @property ?int $day_of_month
 * @property string $format
 * @property string $delivery
 * @property list<string> $recipients
 * @property bool $is_active
 * @property ?Carbon $next_run_on
 * @property ?Carbon $last_run_at
 * @property ?string $last_status
 * @property ?string $last_error
 */
#[UseFactory(ExportScheduleFactory::class)]
final class ExportSchedule extends BaseEnterpriseModel
{
    public const array Frequencies = ['daily', 'weekly', 'monthly'];

    public const array Deliveries = ['email', 'documents', 'both'];

    protected $table = 'analytics_export_schedules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'next_run_on' => 'date',
            'last_run_at' => 'datetime',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DesignedReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(DesignedReport::class, 'designed_report_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
