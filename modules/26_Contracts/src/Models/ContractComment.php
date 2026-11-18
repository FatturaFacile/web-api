<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A review comment on a document version, optionally anchored to a line.
 *
 * @property string $document_version_id
 * @property string|null $author_id
 * @property int|null $line_number
 * @property string $body
 * @property string $status
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 */
final class ContractComment extends BaseEnterpriseModel
{
    protected $table = 'contract_comments';

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'resolved_at' => 'datetime'];
    }
}
