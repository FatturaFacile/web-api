<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadDuplicateCandidate;

interface LeadMergeServiceInterface
{
    /**
     * Lead attributes copied between duplicates.
     */
    public const array MERGEABLE_FIELDS = [
        'first_name', 'last_name', 'company_name', 'title', 'email', 'phone', 'website', 'country',
        'vat_number', 'industry', 'employee_count', 'annual_revenue_estimate', 'state_region',
        'product_line', 'estimated_budget',
    ];

    /**
     * Fills only the empty attributes of an existing lead with the incoming values (auto-merge of a
     * duplicate submission), records the submission as activity and notifies the owner.
     * $submissionKey becomes the activity event external_id, which makes replays of the same submission idempotent.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function enrich(Lead $existing, array $attributes, string $matchType, ?string $sourceCode = null, ?string $submissionKey = null): Lead;

    /**
     * Merges a lead-to-lead duplicate candidate. The survivor keeps its values unless field_choices picks
     * the other record's value; the other lead is archived and points to the survivor.
     *
     * @param  'lead'|'candidate'  $survivor
     * @param  array<string, 'lead'|'candidate'>  $fieldChoices
     */
    public function mergeCandidate(LeadDuplicateCandidate $candidate, string $survivor, array $fieldChoices, string $actorUserId): Lead;

    public function dismissCandidate(LeadDuplicateCandidate $candidate, string $actorUserId): LeadDuplicateCandidate;
}
