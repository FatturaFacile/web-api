<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Strategies;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * EU TED notices and private requests for proposal: no statutory proofs, only the requirements the notice states.
 */
final class GenericTenderStrategy extends AbstractTenderStrategy
{
    public function supports(Jurisdiction $jurisdiction): bool
    {
        return in_array($jurisdiction, [Jurisdiction::EuTed, Jurisdiction::PrivateRfp], true);
    }

    public function regime(): string
    {
        return 'GENERIC';
    }

    public function validateSubmission(TenderNotice $notice, TenderParticipation $participation, Collection $vault, CarbonImmutable $today): array
    {
        return $this->deadlineIssues($notice, $today);
    }

    public function envelopeTemplate(): array
    {
        return [
            'administrative' => $this->checklist([['company_profile', true, false], ['declarations', true, true]]),
            'technical' => $this->checklist([['technical_offer', true, false]]),
            'economic' => $this->checklist([['economic_offer', true, true]]),
        ];
    }

    public function billingReferences(TenderNotice $notice, TenderParticipation $participation): array
    {
        return [
            'cig' => null,
            'cup' => null,
            'rup' => null,
            'dedicated_bank_account' => null,
            'simap_project_number' => $notice->simap_project_number,
            'sdi_reference_block' => null,
        ];
    }
}
