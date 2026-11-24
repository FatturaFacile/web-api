<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use App\Models\User;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ArG Art. 17b al. 2 & OLL 1 Art. 31 Night Work Compensation Bank.
 * Tracks YTD night shifts per employee in a calendar year, whether the >= 25 regular night work threshold is met,
 * the 10% mandatory compensatory rest time accrued/taken, or the 25% cash wage supplement for occasional night work.
 */
final class TimeNightCompensationBalance extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const string ModeCash25Percent = 'cash_25_percent';

    public const string ModeTime10Percent = 'time_10_percent';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'time_night_compensation_balances';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'ytd_night_shifts' => 'integer',
            'is_regular_night_worker' => 'boolean',
            'total_night_minutes' => 'integer',
            'accrued_rest_minutes' => 'integer',
            'taken_rest_minutes' => 'integer',
            'remaining_rest_minutes' => 'integer',
            'cash_supplement_minutes' => 'integer',
        ];
    }
}
