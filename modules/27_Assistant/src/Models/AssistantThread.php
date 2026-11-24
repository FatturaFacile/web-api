<?php

declare(strict_types=1);

namespace Modules\Assistant\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A conversation of one user with the assistant: the questions asked in it are the question logs of the thread.
 *
 * @property Carbon|null $last_message_at
 */
final class AssistantThread extends BaseEnterpriseModel
{
    protected $table = 'assistant_threads';

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The questions of the thread, oldest first.
     *
     * @return HasMany<AssistantQuestionLog, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantQuestionLog::class, 'thread_id')->oldest();
    }
}
