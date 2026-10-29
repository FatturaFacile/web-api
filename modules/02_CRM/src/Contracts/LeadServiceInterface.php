<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Modules\CRM\DTOs\ConvertLeadDTO;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;

interface LeadServiceInterface
{
    public function captureLead(CreateLeadDTO $dto, ?string $actorUserId = null): Lead;

    public function scoreLead(Lead $lead): int;

    public function qualifyLead(Lead $lead, string $actorUserId): Lead;

    public function disqualifyLead(Lead $lead, string $reason, string $actorUserId): Lead;

    /**
     * @return array{account: Account, contact: Contact, opportunity: ?Opportunity}
     */
    public function convertLead(Lead $lead, ConvertLeadDTO $dto, string $actorUserId): array;

    public function detectDuplicates(string $tenantId, ?string $email, ?string $phone, ?string $companyName): Collection;

    public function assignLead(Lead $lead, string $assignedUserId, string $actorUserId): Lead;
}
