<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * @property string $id
 * @property string $quote_id
 * @property string|null $line_id
 * @property string $author_type
 * @property string|null $author_name
 * @property string $body
 */
final class CpqComment extends BaseEnterpriseModel
{
    protected $table = 'cpq_comments';
}
