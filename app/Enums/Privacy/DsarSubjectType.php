<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum DsarSubjectType: string
{
    case CustomerContact = 'customer_contact';
    case Employee = 'employee';
    case Candidate = 'candidate';
    case Lead = 'lead';
    case SupplierContact = 'supplier_contact';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Subject type of the personal data registry (App\Services\Privacy\PersonalDataRegistry) this type maps to; null
     * when the registry has no subject table for it.
     */
    public function registryType(): ?string
    {
        return match ($this) {
            self::CustomerContact => 'contact',
            self::Employee => 'employee',
            self::Candidate => 'applicant',
            self::Lead => 'lead',
            self::SupplierContact => null,
        };
    }
}
