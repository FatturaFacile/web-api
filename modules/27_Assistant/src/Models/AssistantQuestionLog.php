<?php

declare(strict_types=1);

namespace Modules\Assistant\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Every question asked, whatever its outcome, with the thread it belongs to and the feedback of the user.
 */
final class AssistantQuestionLog extends BaseEnterpriseModel
{
    /**
     * @var list<string>
     */
    public const array Ratings = ['helpful', 'not_helpful'];

    protected $table = 'assistant_question_logs';

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'row_count' => 'integer',
            'feedback_at' => 'datetime',
        ];
    }
}
