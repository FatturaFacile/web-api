<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\ValueObjects;

/**
 * Result of pre-export SuisseTax XML XSD & business validation.
 */
final readonly class SuisseTaxValidationResult
{
    /**
     * @param  list<string>  $xsdErrors
     * @param  list<string>  $businessErrors
     */
    public function __construct(
        public bool $valid,
        public array $xsdErrors,
        public array $businessErrors,
        public bool $quadraturaPassed,
        public string $schemaVersion = 'eCH-0217-v2.0',
    ) {}

    /**
     * @return list<string>
     */
    public function allErrors(): array
    {
        return [...$this->xsdErrors, ...$this->businessErrors];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'xsd_valid' => $this->xsdErrors === [],
            'quadratura_passed' => $this->quadraturaPassed,
            'schema_version' => $this->schemaVersion,
            'xsd_errors' => $this->xsdErrors,
            'business_errors' => $this->businessErrors,
            'errors' => $this->allErrors(),
        ];
    }
}
