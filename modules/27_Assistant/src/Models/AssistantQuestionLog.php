<?php

declare(strict_types=1);

namespace Modules\Assistant\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Every question asked, whatever its outcome.
 */
final class AssistantQuestionLog extends BaseEnterpriseModel
{
    protected $table = 'assistant_question_logs';

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'row_count' => 'integer',
        ];
    }
}
