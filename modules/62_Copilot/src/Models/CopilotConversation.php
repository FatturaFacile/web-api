<?php

declare(strict_types=1);

namespace Modules\Copilot\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation of one user with the Copilot, with the ERP screen it was opened from.
 *
 * @property string $user_id
 * @property string $title
 * @property string $locale
 * @property string|null $context_route
 * @property string|null $context_entity_type
 * @property string|null $context_entity_id
 */
final class CopilotConversation extends BaseEnterpriseModel
{
    protected $table = 'copilot_conversations';

    /**
     * The title is cut from the first prompt, so it stays out of the audit ledger.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        return array_diff_key($attributes, ['title' => true]);
    }

    /**
     * @return HasMany<CopilotMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CopilotMessage::class, 'conversation_id')->oldest();
    }
}
