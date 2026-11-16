<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use App\Models\User;
use Database\Factories\ReportDefinitionFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved report: the indicators to show, the period (a preset that moves with the calendar, or fixed dates) and the
 * comparison. It belongs to the user who saved it; a shared definition can be run by every analytics reader of the
 * company but changed only by its owner or an administrator.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property list<string> $kpis
 * @property string $period_preset
 * @property bool $is_shared
 * @property ?string $description
 * @property string $compare
 * @property ?Carbon $from_date
 * @property ?Carbon $to_date
 */
#[UseFactory(ReportDefinitionFactory::class)]
final class ReportDefinition extends BaseEnterpriseModel
{
    /**
     * Presets resolved on the calendar of the company when the report runs.
     *
     * @var list<string>
     */
    public const array Presets = ['custom', 'month_to_date', 'last_month', 'quarter_to_date', 'year_to_date', 'last_30_days'];

    protected $table = 'analytics_report_definitions';

    /**
     * Calculated grid columns as portable SQL, so they filter, sort, group and list their values in the database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            // Booleans are cast to different texts by SQLite and PostgreSQL: a text state is the same on both.
            'sharing_state' => "CASE WHEN analytics_report_definitions.is_shared THEN 'shared' ELSE 'private' END",
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kpis' => 'array',
            'from_date' => 'date',
            'to_date' => 'date',
            'is_shared' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
