<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\EmployeeDocumentFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document of the personnel file. The storage path never leaves the server; documents with health data are
 * confidential and reach only HR roles, and the employee when HR makes them visible.
 *
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiry_date
 * @property Carbon|null $retention_until
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property bool $contains_health_data
 * @property bool $visible_to_employee
 * @property string|null $uploaded_by
 */
#[UseFactory(EmployeeDocumentFactory::class)]
final class EmployeeDocument extends BaseEnterpriseModel
{
    protected $table = 'hr_employee_documents';

    /**
     * @var list<string>
     */
    protected $hidden = ['file_path'];

    /**
     * What enters the immutable ledger: no storage path and no free metadata.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['file_path'], $attributes['metadata']);

        return $attributes;
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'retention_until' => 'date:Y-m-d',
            'is_confidential' => 'boolean',
            'contains_health_data' => 'boolean',
            'visible_to_employee' => 'boolean',
            'size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
