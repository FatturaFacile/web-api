<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\SupportMailbox;

/**
 * A support mailbox without its credentials.
 *
 * @mixin SupportMailbox
 */
final class SupportMailboxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email_address' => $this->email_address,
            'imap_host' => $this->imap_host,
            'imap_port' => (int) $this->imap_port,
            'has_credentials' => $this->imap_password !== null && $this->imap_password !== '',
            'default_team_id' => $this->default_team_id,
            'default_category_id' => $this->default_category_id,
            'default_priority' => $this->default_priority,
            'is_active' => (bool) $this->is_active,
            'last_uid' => (int) $this->last_uid,
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
