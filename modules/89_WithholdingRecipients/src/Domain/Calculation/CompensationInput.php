<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Carbon\CarbonImmutable;
use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * Everything a calculator needs about one compensation: the amount, the payee facts and what was already paid in the tax year.
 */
final readonly class CompensationInput
{
    public function __construct(
        public RecipientRegime $regime,
        public Money $gross,
        public CarbonImmutable $paymentDate,
        public int $taxYear,
        public ?CarbonImmutable $birthDate = null,
        public ?string $taxCode = null,
        /** Posted compensations of this payee to this company in the tax year. */
        public Money $priorCompanyIncome = new Money(0),
        /** Self-certified income received from other clients in the same year (occasional work, amateur sports). */
        public Money $thirdPartyIncome = new Money(0),
        /** AVS base already charged by this employer in the tax year. */
        public Money $priorAvsBase = new Money(0),
        public bool $hasReducedWithholdingCertificate = false,
        public ?EnasarcoMandate $enasarcoMandate = null,
        public bool $firrEnabled = false,
        public bool $culturalSector = false,
        public ?ActivityType $activityType = null,
        public int $performanceDays = 1,
        public ?Money $acquisitionExpenses = null,
        public ?string $canton = null,
    ) {}
}
