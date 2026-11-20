<?php

declare(strict_types=1);

namespace Modules\Copilot\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Copilot\Enums\ActionStatus;
use Modules\Copilot\Enums\MessageRole;

/**
 * One message of a conversation. An assistant message may carry the action staged for human confirmation.
 *
 * @property string $conversation_id
 * @property MessageRole $role
 * @property string $content_markdown
 * @property array<int, array<string, mixed>>|null $citations_json
 * @property array<string, mixed>|null $visualization_json
 * @property array<string, mixed>|null $proposed_action_json
 * @property ActionStatus|null $action_status
 * @property array<string, mixed>|null $token_usage_json
 */
final class CopilotMessage extends BaseEnterpriseModel
{
    protected $table = 'copilot_messages';

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'action_status' => ActionStatus::class,
            'citations_json' => 'array',
            'visualization_json' => 'array',
            'proposed_action_json' => 'array',
            'token_usage_json' => 'array',
        ];
    }

    /**
     * What a person typed, and the business data an answer or action carries, stay out of the ledger: the Copilot audit
     * events record who did what, not the text.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(['content_markdown', 'citations_json', 'visualization_json', 'proposed_action_json', 'token_usage_json']));
    }

    /**
     * @return BelongsTo<CopilotConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CopilotConversation::class, 'conversation_id');
    }
}
