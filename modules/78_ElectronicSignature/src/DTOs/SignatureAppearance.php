<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

use DateTimeImmutable;

/**
 * What the signature field of a PDF shows and records: who signed, at which tier, when and where on the page. The box is
 * in fractions of the page (0..1, origin at the top left) so it does not depend on the resolution the page was drawn at.
 */
final readonly class SignatureAppearance
{
    /**
     * @param  array{page: int, x: float, y: float, width: float, height: float}|null  $box  null for an invisible signature
     * @param  list<string>  $lines  text lines drawn inside the box
     */
    public function __construct(
        public string $signerName,
        public DateTimeImmutable $signedAt,
        public string $reason,
        public ?string $location = null,
        public ?array $box = null,
        public array $lines = [],
    ) {}
}
