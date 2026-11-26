<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\SecurityAuditCatalog;
use App\Services\Auth\Security\SecurityAuditReader;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * An authentication or security event of the audit ledger as the viewer shows it. The details keys it reads are the
 * standard ones of RegistrationAudit (`status`, `auth_method`, `mfa_method`, `reason`); the IAM engine events spell them
 * in camel case (`authMethod`), and older entries fall back to what their action implies. No other detail is exposed.
 *
 * @mixin AuditLedgerEntry
 */
final class SecurityAuditEventResource extends JsonResource
{
    /**
     * @param  array<string, User>  $users  the accounts of the page, by id
     */
    public function __construct($resource, private readonly array $users = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditLedgerEntry $entry */
        $entry = $this->resource;
        $details = (array) $entry->payload_after;
        $action = strtoupper((string) $entry->action);
        $userId = SecurityAuditReader::userIdOf($entry);
        $user = $userId === null ? null : ($this->users[$userId] ?? null);

        return [
            'id' => $entry->id,
            'occurred_at' => Carbon::parse($entry->event_timestamp)->toIso8601String(),
            'tenant_id' => $entry->tenant_id,
            'user_id' => $userId,
            'actor_user_id' => $entry->actor_user_id,
            'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'event_type' => $action,
            'category' => SecurityAuditCatalog::category($action),
            'translation_key' => SecurityAuditCatalog::translationKey($action),
            'status' => $this->string($details['status'] ?? null) ?? RegistrationAudit::statusOf($action),
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'auth_method' => $this->string($details['auth_method'] ?? ($details['authMethod'] ?? null)) ?? RegistrationAudit::authMethodOf($action, $details),
            'mfa_method' => $this->string($details['mfa_method'] ?? ($details['mfaMethod'] ?? null)),
            'reason' => $this->string($details['reason'] ?? null),
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
