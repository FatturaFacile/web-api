<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\TalentLearning\Contracts\HrDirectory;

/**
 * Attaches the employee of human resources (name, position, department) to records that hold an employee id, in one
 * lookup for the whole list, as the "employee" relation the resources read.
 */
final readonly class EmployeeAnnotator
{
    public function __construct(private HrDirectory $directory) {}

    /**
     * @param  iterable<Model>  $records
     * @return list<Model>
     */
    public function annotate(string $tenantId, iterable $records, string $column = 'employee_id', string $relation = 'employee'): array
    {
        $records = is_array($records) ? array_values($records) : iterator_to_array($records, false);
        $ids = [];

        foreach ($records as $record) {
            if ($record->getAttribute($column) !== null) {
                $ids[] = (string) $record->getAttribute($column);
            }
        }

        $employees = $this->directory->employeesByIds($tenantId, $ids);

        foreach ($records as $record) {
            $id = $record->getAttribute($column);
            $record->setRelation($relation, $id !== null && isset($employees[(string) $id]) ? $employees[(string) $id]->toArray() : null);
        }

        return $records;
    }
}
