<?php

declare(strict_types=1);

namespace App\DTOs\Fiscal;

use DateTimeImmutable;

/**
 * Outcome of an intra-Community VAT number check.
 *
 * `status` is one of: valid, invalid, unavailable. An unavailable VIES never blocks the user: `isValid` is then null
 * (unknown) and `errorCode` carries the VIES reason (MS_UNAVAILABLE, SERVICE_UNAVAILABLE, TIMEOUT, ...).
 */
final readonly class VatValidationResult
{
    public const string STATUS_VALID = 'valid';

    public const string STATUS_INVALID = 'invalid';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        public ?bool $isValid,
        public string $countryCode,
        public string $vatNumber,
        public ?string $traderName,
        public ?string $traderAddress,
        public ?string $requestIdentifier,
        public DateTimeImmutable $validatedAt,
        public ?string $errorCode = null,
        public bool $fromCache = false,
    ) {}

    public function withFromCache(): self
    {
        return new self($this->status, $this->isValid, $this->countryCode, $this->vatNumber, $this->traderName, $this->traderAddress, $this->requestIdentifier, $this->validatedAt, $this->errorCode, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'is_valid' => $this->isValid,
            'country_code' => $this->countryCode,
            'vat_number' => $this->vatNumber,
            'trader_name' => $this->traderName,
            'trader_address' => $this->traderAddress,
            'request_identifier' => $this->requestIdentifier,
            'validated_at' => $this->validatedAt->format(DATE_ATOM),
            'error_code' => $this->errorCode,
            'from_cache' => $this->fromCache,
        ];
    }
}
