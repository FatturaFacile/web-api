<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\CollaborationRegime;
use Modules\SkiOutdoorSchool\Domain\Enums\LicenseType;
use Modules\SkiOutdoorSchool\Domain\Enums\RetentionMode;

/**
 * Ski instructor, mountain guide or outdoor guide of the school, with licence, rate, secretariat retention rule and collaboration regime.
 *
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property LicenseType $license_type
 * @property Carbon|null $license_valid_until
 * @property string|null $license_number
 * @property string $hourly_rate
 * @property RetentionMode $retention_mode
 * @property string $retention_value
 * @property CollaborationRegime $collaboration_regime
 * @property string $withholding_percent
 * @property string|null $max_weekly_hours
 * @property bool $is_active
 */
final class Instructor extends BaseEnterpriseModel
{
    protected $table = 'ski_instructors';

    protected function casts(): array
    {
        return [
            'license_type' => LicenseType::class,
            'license_valid_until' => 'date:Y-m-d',
            'hourly_rate' => 'decimal:2',
            'retention_mode' => RetentionMode::class,
            'retention_value' => 'decimal:4',
            'collaboration_regime' => CollaborationRegime::class,
            'withholding_percent' => 'decimal:2',
            'max_weekly_hours' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<InstructorLanguage, $this>
     */
    public function languages(): HasMany
    {
        return $this->hasMany(InstructorLanguage::class, 'instructor_id');
    }

    /**
     * @return HasMany<InstructorDiscipline, $this>
     */
    public function disciplines(): HasMany
    {
        return $this->hasMany(InstructorDiscipline::class, 'instructor_id');
    }

    /**
     * @return HasMany<InstructorAvailability, $this>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(InstructorAvailability::class, 'instructor_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return list<string>
     */
    public function languageCodes(): array
    {
        return $this->languages->pluck('language_code')->map(static fn (mixed $code): string => strtoupper((string) $code))->sort()->values()->all();
    }

    /**
     * @return list<string>
     */
    public function disciplineValues(): array
    {
        return $this->disciplines->map(static fn (InstructorDiscipline $row): string => $row->discipline->value)->sort()->values()->all();
    }
}
