<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use Carbon\CarbonInterface;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\HumanResources\Services\TimeTracking\DataRetentionPolicy;

/**
 * The single place that states how long a record of a statutory class must be kept, and the guard the document
 * cleanups (dms:retention, hr:purge-employee-documents) consult before they delete or archive such a record. The time
 * purge reads its floor from here; the privacy anonymiser refuses every class that is not erasable.
 *
 * The periods are the ones the code already applies: accounting, tax and payroll vouchers ten years (Swiss CO art.
 * 958f, Italian c.c. art. 2220), working time records five years (ArG art. 46, ArGV 1 art. 73, OLL 1 art. 73 cpv. 2,
 * read from DataRetentionPolicy). Contracts follow the ten years of the ordinary prescription (Italian c.c. art. 2946),
 * taken as the cautious choice by PreservationDocumentClass. They are NOT verified against the official texts and must
 * be confirmed by the accountant or counsel. A non-erasable class without a period (evidence and audit trails) is held
 * indefinitely, so an automatic cleanup never removes it. Nothing here is legal advice.
 */
final class StatutoryRetentionCatalogue
{
    /** Years accounting, tax and payroll vouchers are kept: Swiss CO art. 958f, Italian c.c. art. 2220. */
    public const FISCAL_RECORD_YEARS = 10;

    /** Minimum years working time records are kept: ArG art. 46, ArGV 1 art. 73, OLL 1 art. 73 cpv. 2. */
    public const STATUTORY_EMPLOYMENT_YEARS = DataRetentionPolicy::MINIMUM_YEARS;

    /**
     * Retention rule of every class that automatic cleanups must not erase. A null period means held indefinitely.
     *
     * @return array<string, array{retention: PersonalDataRetention, years: int|null, legalBasis: list<string>, pendingConfirmation: bool}>
     */
    public static function rules(): array
    {
        return [
            PersonalDataRetention::FiscalRecord->value => [
                'retention' => PersonalDataRetention::FiscalRecord,
                'years' => self::FISCAL_RECORD_YEARS,
                'legalBasis' => ['Swiss CO art. 958f', 'Italian c.c. art. 2220', 'Italian c.c. art. 2946 (contracts, cautious choice)'],
                'pendingConfirmation' => true,
            ],
            PersonalDataRetention::StatutoryEmployment->value => [
                'retention' => PersonalDataRetention::StatutoryEmployment,
                'years' => self::STATUTORY_EMPLOYMENT_YEARS,
                'legalBasis' => ['ArG art. 46', 'ArGV 1 art. 73', 'OLL 1 art. 73 cpv. 2'],
                'pendingConfirmation' => true,
            ],
            PersonalDataRetention::Evidence->value => [
                'retention' => PersonalDataRetention::Evidence,
                'years' => null,
                'legalBasis' => ['GDPR art. 7(1) (consent proof)', 'accountability (audit trails)'],
                'pendingConfirmation' => true,
            ],
        ];
    }

    /**
     * True when the class has a catalogue rule, which is every class except Erasable.
     */
    public static function covers(PersonalDataRetention $retention): bool
    {
        return isset(self::rules()[$retention->value]);
    }

    /**
     * Minimum years to keep a record of the class; null when the class is erasable or is held with no period.
     */
    public static function minimumYears(PersonalDataRetention $retention): ?int
    {
        return self::rules()[$retention->value]['years'] ?? null;
    }

    /**
     * Retention class of a document of the library, by its document type. Only the types that the code itself files
     * under a preservation class map; any other type is not classified here.
     */
    public static function retentionForDocumentType(?string $documentType): ?PersonalDataRetention
    {
        if ($documentType === null || PreservationDocumentClass::tryFrom($documentType) === null) {
            return null;
        }

        return PersonalDataRetention::FiscalRecord;
    }

    /**
     * True while a record of the class must still be kept: the period has not elapsed from the record date, the
     * record has no date to count from, or the class has no period and is held indefinitely. An erasable class is
     * never under statutory retention.
     */
    public static function isUnderRetention(PersonalDataRetention $retention, ?CarbonInterface $recordDate, ?CarbonInterface $now = null): bool
    {
        if (! self::covers($retention)) {
            return false;
        }

        $years = self::minimumYears($retention);

        if ($years === null || $recordDate === null) {
            return true;
        }

        return $recordDate->copy()->addYears($years)->isAfter($now ?? now());
    }
}
