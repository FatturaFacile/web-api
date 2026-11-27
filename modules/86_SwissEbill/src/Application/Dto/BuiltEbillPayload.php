<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Dto;

use Modules\SwissEbill\Domain\Enums\EbillChannelType;

final readonly class BuiltEbillPayload
{
    public function __construct(
        public string $xml,
        public string $xmlSha256,
        public string $billerId,
        public string $recipientId,
        public EbillChannelType $channelType,
        public ?string $pdfSha256,
        public ?int $pdfSizeBytes,
        public string $version,
    ) {}
}
