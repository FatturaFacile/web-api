<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A saved column and delimiter mapping of a bank's CSV statement export.
 *
 * @property string $name
 * @property string $delimiter
 * @property bool $has_header
 * @property string $date_format
 * @property string $decimal_separator
 * @property string|null $thousands_separator
 * @property string|null $account_iban
 * @property string $currency
 * @property array<string, string> $columns
 */
final class StatementCsvProfile extends BaseEnterpriseModel
{
    protected $table = 'statement_csv_profiles';

    protected function casts(): array
    {
        return ['has_header' => 'boolean', 'columns' => 'array'];
    }
}
