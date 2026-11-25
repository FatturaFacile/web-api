<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

use Illuminate\Http\UploadedFile;

final readonly class UploadAndParseTachographDddCommand
{
    public function __construct(
        public UploadedFile $file,
        public ?string $driverEmployeeId,
        public ?string $vehicleId,
        public ?string $userId,
    ) {}
}
