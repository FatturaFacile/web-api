<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use Modules\PassengerTransport\Domain\Enums\IssueSeverity;

/**
 * A finding of the driving-time or document checks. The code is a translation key
 * (passenger_transport.compliance.<code> and passengerTransport.compliance.<code> in the frontend dictionary); the citation is the
 * legal reference and stays untranslated.
 */
final readonly class ComplianceIssue
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public string $code,
        public IssueSeverity $severity,
        public string $citation,
        public array $params = [],
    ) {}

    public function isBlocking(): bool
    {
        return $this->severity === IssueSeverity::Blocking;
    }

    public function demoted(): self
    {
        return new self($this->code, IssueSeverity::Warning, $this->citation, $this->params);
    }

    /**
     * @return array{code: string, severity: string, citation: string, params: array<string, scalar|null>, message: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'citation' => $this->citation,
            'params' => $this->params,
            'message' => (string) __('passenger_transport.compliance.'.$this->code, $this->params),
        ];
    }
}
