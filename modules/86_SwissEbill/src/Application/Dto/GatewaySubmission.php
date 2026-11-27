<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Dto;

use Modules\SwissEbill\Domain\Enums\EbillChannelType;

final readonly class GatewaySubmission
{
    public function __construct(
        public string $idempotencyKey,
        public string $billerId,
        public string $recipientId,
        public EbillChannelType $channelType,
        public string $invoiceNumber,
        public string $xml,
        public string $xmlSha256,
    ) {}
}
