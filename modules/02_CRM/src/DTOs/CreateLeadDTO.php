<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class CreateLeadDTO
{
    /**
     * @param  array<int|string, mixed>  $tags
     * @param  array<string, mixed>  $customFields
     * @param  string|null  $duplicateStrategy  merge | notify | create (null = config default)
     */
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $companyName,
        public ?string $email,
        public ?string $phone,
        public ?string $title = null,
        public ?string $website = null,
        public string $country = 'IT',
        public ?string $leadSourceCode = null,
        public ?string $campaignId = null,
        public ?string $assignedUserId = null,
        public ?float $estimatedBudget = null,
        public array $tags = [],
        public array $customFields = [],
        public ?string $vatNumber = null,
        public ?string $industry = null,
        public ?string $region = null,
        public ?string $productLine = null,
        public ?int $companySize = null,
        public ?float $estimatedRevenue = null,
        public int $emailOpens = 0,
        public int $catalogDownloads = 0,
        public int $siteVisits = 0,
        public ?string $leadSourceId = null,
        public ?string $duplicateStrategy = null,
        public ?int $employeeCount = null,
        public ?float $annualRevenueEstimate = null,
        public ?string $stateRegion = null,
        public ?string $externalId = null,
    ) {}

    /**
     * Validation view of the DTO, used by the intake pipeline.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company_name' => $this->companyName,
            'email' => $this->email,
            'phone' => $this->phone,
            'title' => $this->title,
            'website' => $this->website,
            'country' => $this->country,
            'estimated_budget' => $this->estimatedBudget,
            'vat_number' => $this->vatNumber,
            'industry' => $this->industry,
            'employee_count' => $this->employeeCount ?? $this->companySize,
            'annual_revenue_estimate' => $this->annualRevenueEstimate ?? $this->estimatedRevenue,
            'state_region' => $this->stateRegion ?? $this->region,
            'product_line' => $this->productLine,
            'external_id' => $this->externalId,
        ];
    }
}
