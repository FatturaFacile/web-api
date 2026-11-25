<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Employee;
use Modules\PassengerTransport\Domain\Enums\DddFileType;

/**
 * An archived tachograph download (.DDD) of a driver card or a vehicle unit, with its SHA-256 for legal archiving.
 *
 * @property string|null $driver_employee_id
 * @property string|null $vehicle_id
 * @property DddFileType $file_type
 * @property string $original_name
 * @property string $storage_path
 * @property int $file_size
 * @property string $sha256
 * @property string|null $card_number
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property int $infringements_count
 * @property list<array{code: string, date: string, minutes: int}>|null $infringements_summary
 */
final class DddArchive extends BaseEnterpriseModel
{
    protected $table = 'ptr_ddd_archives';

    protected function casts(): array
    {
        return [
            'file_type' => DddFileType::class,
            'file_size' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'infringements_count' => 'integer',
            'infringements_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_employee_id');
    }
}
