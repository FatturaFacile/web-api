<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

/**
 * `SendAndSignJobOfferCommand`: create the offer letter, render it and (optionally) request the signature.
 */
final readonly class SendJobOfferDTO
{
    public function __construct(
        public string $applicationId,
        public float $proposedSalary,
        public string $startDate,
        public ?string $currency = null,
        public ?string $employmentType = null,
        public ?string $contractType = null,
        public ?float $weeklyHours = null,
        public ?int $probationMonths = null,
        public ?string $validUntil = null,
        public bool $send = true,
        public ?string $actorUserId = null
    ) {}
}
