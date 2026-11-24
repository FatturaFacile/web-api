<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A safety briefing signed on glass by the attending workers.
 *
 * @property string $topic
 * @property string|null $site_or_work_center
 * @property list<string>|null $checklist_points_json
 * @property string|null $supervisor_employee_id
 * @property Carbon $conducted_at
 */
final class ToolBoxTalkSession extends BaseEnterpriseModel
{
    protected $table = 'hse_toolbox_talks';

    protected function casts(): array
    {
        return ['checklist_points_json' => 'array', 'conducted_at' => 'datetime'];
    }

    /**
     * @return HasMany<ToolBoxTalkAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(ToolBoxTalkAttendance::class, 'toolbox_talk_id');
    }
}
