<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Associations\Models\Member;
use Modules\Associations\Support\CsvFile;

/**
 * The registry as CSV, in the format the import accepts. Health data is never exported.
 */
final class RegistryExportService
{
    /**
     * @param  Builder<Member>  $query
     */
    public function csv(Builder $query): string
    {
        return CsvFile::write(RegistryImportService::Columns, $this->rows($query));
    }

    /**
     * @param  Builder<Member>  $query
     * @return iterable<array<int, mixed>>
     */
    private function rows(Builder $query): iterable
    {
        foreach ($query->with(['contact', 'registryAssignments.registryType'])->lazyById() as $member) {
            $contact = $member->contact;

            yield [
                $contact->first_name,
                $contact->last_name,
                $contact->email,
                $contact->phone,
                $member->tax_code,
                $member->birth_date,
                $member->birth_place,
                $member->gender,
                $member->registryAssignments->last()?->registryType?->code,
            ];
        }
    }
}
