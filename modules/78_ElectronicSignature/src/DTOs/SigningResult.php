<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

/**
 * The outcome of a signing attempt. `signed` is final; `pending` means the provider waits for the signer (confirmation on
 * the phone) and the result arrives later, by polling or a callback.
 */
final readonly class SigningResult
{
    public const string Signed = 'signed';

    public const string Pending = 'pending';

    /**
     * @param  string|null  $pdf  the working copy with this signature applied (hash-signing providers), null when the PDF is untouched
     * @param  array<string, mixed>  $evidence  provider evidence kept in the audit trail (transaction id, method, time stamp)
     */
    public function __construct(
        public string $status,
        public string $method,
        public ?string $pdf = null,
        public ?string $certificateSerial = null,
        public ?string $certificateSubject = null,
        public ?string $externalId = null,
        public ?string $receipt = null,
        public array $evidence = [],
        public ?string $preparedPdf = null,
    ) {}

    public function isSigned(): bool
    {
        return $this->status === self::Signed;
    }
}
