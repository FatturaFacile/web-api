<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Audit log and generated payload of a DBMilch.ch / TSM or SIAN declaration.
 *
 * @property string $authority
 * @property string $period
 * @property string $status
 * @property string $format
 * @property int $record_count
 * @property string|null $checksum
 * @property Carbon $generated_at
 * @property Carbon|null $submitted_at
 * @property string|null $file_name
 * @property array<int,string>|null $validation_errors
 */
final class RegulatorySubmission extends BaseEnterpriseModel
{
    protected $table = 'dairy_regulatory_submissions';

    /**
     * @var list<string>
     */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'record_count' => 'integer',
            'validation_errors' => 'array',
        ];
    }
}
