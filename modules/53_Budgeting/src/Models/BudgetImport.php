<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * An Excel or CSV import of budget lines into a draft version: the outcome and the row by row errors.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $version_id
 * @property string $file_name
 * @property string $format
 * @property string $mode
 * @property string $status
 * @property int $total_rows
 * @property int $imported_rows
 * @property int $error_rows
 * @property list<array{row: int, field: string, message: string}>|null $errors
 * @property string|null $user_id
 */
final class BudgetImport extends BaseEnterpriseModel
{
    public const string Imported = 'imported';

    public const string Failed = 'failed';

    public const string Validated = 'validated';

    public const string ModeReplace = 'replace';

    public const string ModeMerge = 'merge';

    public const array Modes = [self::ModeReplace, self::ModeMerge];

    protected $table = 'budget_imports';

    protected function casts(): array
    {
        return ['total_rows' => 'integer', 'imported_rows' => 'integer', 'error_rows' => 'integer', 'errors' => 'array'];
    }
}
