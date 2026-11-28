<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Commands;

/**
 * AEO internal control of an authorization: WMS balances against the customs book, CGU thresholds, usual handling in progress and the
 * security checks of the carriers and suppliers named.
 */
final readonly class RunAeoReconciliationAndComplianceCheckCommand
{
    /**
     * @param  list<array{partner_or_carrier_name: string, partner_aeo_status: string, security_seal_verified: bool, findings: ?string, corrective_action: ?string}>  $partnerChecks
     */
    public function __construct(
        public string $authorizationId,
        public string $auditDate,
        public array $partnerChecks,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId): self
    {
        /** @var list<array{partner_or_carrier_name: string, partner_aeo_status: string, security_seal_verified: bool, findings: ?string, corrective_action: ?string}> $checks */
        $checks = array_map(static fn (array $row): array => [
            'partner_or_carrier_name' => (string) $row['partner_or_carrier_name'],
            'partner_aeo_status' => (string) $row['partner_aeo_status'],
            'security_seal_verified' => (bool) ($row['security_seal_verified'] ?? false),
            'findings' => isset($row['findings']) ? (string) $row['findings'] : null,
            'corrective_action' => isset($row['corrective_action']) ? (string) $row['corrective_action'] : null,
        ], array_values((array) ($data['partner_checks'] ?? [])));

        return new self((string) $data['authorization_id'], (string) ($data['audit_date'] ?? now()->toDateString()), $checks, $userId);
    }
}
