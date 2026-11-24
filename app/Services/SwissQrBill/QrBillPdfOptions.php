<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

final readonly class QrBillPdfOptions
{
    public const MODE_A4_ATTACHED = 'A4_ATTACHED';

    public const MODE_STANDALONE = 'STANDALONE';

    public const PERFORATION_NONE = 'NONE';

    public const PERFORATION_DASHED_LINE = 'DASHED_LINE';

    public const PERFORATION_DASHED_LINE_WITH_SCISSORS = 'DASHED_LINE_WITH_SCISSORS';

    public const MODES = [self::MODE_A4_ATTACHED, self::MODE_STANDALONE];

    public const PERFORATIONS = [self::PERFORATION_NONE, self::PERFORATION_DASHED_LINE, self::PERFORATION_DASHED_LINE_WITH_SCISSORS];

    public const LANGUAGES = ['it', 'de', 'fr', 'en'];

    public function __construct(
        public string $mode = self::MODE_A4_ATTACHED,
        public string $perforation = self::PERFORATION_DASHED_LINE_WITH_SCISSORS,
        public string $language = 'en',
    ) {}
}
