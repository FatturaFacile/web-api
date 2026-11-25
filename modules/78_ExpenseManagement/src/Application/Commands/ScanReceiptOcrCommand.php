<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Illuminate\Http\UploadedFile;

final readonly class ScanReceiptOcrCommand
{
    public function __construct(
        public UploadedFile $file,
        public ?string $clientText,
        public string $tenantId,
        public ?string $userId,
    ) {}
}
