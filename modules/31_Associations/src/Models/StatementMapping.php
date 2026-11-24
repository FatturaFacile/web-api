<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * Which line of a statement scheme a ledger account feeds, optionally only for one cost centre, and whether the line
 * counts as commercial income for the prevalence check.
 *
 * @property string $scheme
 * @property string $line_code
 * @property string $account_id
 * @property ?string $cost_center_id
 * @property bool $is_commercial
 */
final class StatementMapping extends AssociationRecord
{
    protected $table = 'assoc_statement_mappings';

    protected function casts(): array
    {
        return ['is_commercial' => 'boolean'];
    }
}
