<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadSourceCredentialServiceInterface;
use Modules\CRM\Models\LeadSource;

final readonly class LeadSourceCredentialService implements LeadSourceCredentialServiceInterface
{
    private const string API_KEY_PREFIX = 'ffl_';

    public function __construct(
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function rotate(LeadSource $source, string $which = 'both', ?string $actorUserId = null): array
    {
        $plain = [];
        $changes = [];

        if ($which === 'both' || $which === 'webhook_secret') {
            $plain['webhook_secret'] = Str::random(48);
            $changes['webhook_secret'] = $plain['webhook_secret'];
        }

        if ($which === 'both' || $which === 'api_key') {
            $apiKey = self::API_KEY_PREFIX.Str::random(40);
            $plain['api_key'] = $apiKey;
            $changes['api_key_prefix'] = substr($apiKey, 0, 12);
            $changes['api_key_hash'] = hash('sha256', $apiKey);
        }

        // saveQuietly(): the generic Auditable hook would otherwise copy the changed credential columns.
        $source->forceFill($changes)->saveQuietly();

        // Only record which credential changed: secrets never reach the audit ledger.
        $this->auditLedger->record(
            action: 'LEAD_SOURCE_CREDENTIALS_ROTATED',
            model: $source,
            payloadBefore: null,
            payloadAfter: ['rotated' => array_keys($plain), 'actor_user_id' => $actorUserId],
            moduleCode: 'MODULE_02_CRM'
        );

        return $plain;
    }
}
