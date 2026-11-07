<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A notified case of short-time work or bad weather for the company or a department.
 */
final class ShortTimeCase extends PayrollRecord
{
    public const string ShortTime = 'short_time';

    public const string BadWeather = 'bad_weather';

    /** @var list<string> */
    public const array Kinds = [self::ShortTime, self::BadWeather];

    protected $table = 'payroll_short_time_cases';

    /**
     * The topic of the legal parameters of this kind of case.
     */
    public function parameterTopic(): string
    {
        return $this->kind === self::BadWeather ? 'bad_weather' : 'short_time_work';
    }

    /**
     * @return HasMany<ShortTimeClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(ShortTimeClaim::class, 'short_time_case_id');
    }

    protected function casts(): array
    {
        return ['notified_on' => 'date:Y-m-d', 'is_urgent' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }
}
