<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Handlers;

use Modules\DataResidency\Application\Commands\ValidateCrossBorderTransferCommand;
use Modules\DataResidency\Infrastructure\Services\CrossBorderEgressGuard;
use Modules\DataResidency\Infrastructure\Services\ResidencyPolicyResolver;

/**
 * Judges a destination against the policy of the tenant or entity without side effects: the answer of the "test an outbound
 * endpoint" API. The enforcing path (webhooks, connectors, AI calls, exports) is CrossBorderEgressGuard::assertAllowed(),
 * which blocks with HTTP 451 and records the alert.
 */
final class ValidateCrossBorderTransferHandler
{
    public function __construct(
        private readonly ResidencyPolicyResolver $policies,
        private readonly CrossBorderEgressGuard $guard,
    ) {}

    /**
     * @return array{allowed: bool, destination_country_iso2: string|null, decision_status: string, jurisdiction_zone: string|null, reason: string|null, legal_basis_reference: string|null}
     */
    public function handle(ValidateCrossBorderTransferCommand $command): array
    {
        $policy = $this->policies->resolve($command->tenantId, $command->legalEntityId);

        if ($policy === null) {
            return ['allowed' => true, 'destination_country_iso2' => $command->destinationCountryIso2, 'decision_status' => 'ALLOWED', 'jurisdiction_zone' => null, 'reason' => 'No residency policy is configured.', 'legal_basis_reference' => null];
        }

        $evaluation = $this->guard->evaluate($policy, $command->target, $command->destinationCountryIso2);

        return [
            'allowed' => $evaluation['allowed'],
            'destination_country_iso2' => $evaluation['country'],
            'decision_status' => $evaluation['decision']->value,
            'jurisdiction_zone' => $policy->jurisdiction_zone->value,
            'reason' => $evaluation['reason'],
            'legal_basis_reference' => $evaluation['legal_basis'],
        ];
    }
}
