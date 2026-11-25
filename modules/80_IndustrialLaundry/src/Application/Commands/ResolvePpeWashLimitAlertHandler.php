<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Domain\Enums\PpeAction;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Services\PpeLimitService;

final class ResolvePpeWashLimitAlertHandler
{
    public const string CompleteMending = 'complete_mending';

    public function __construct(private readonly PpeLimitService $ppe) {}

    public function handle(ResolvePpeWashLimitAlertCommand $command): Garment
    {
        $garment = Garment::query()->findOrFail($command->garmentId);

        if ($command->action === self::CompleteMending) {
            return $this->ppe->completeMending($garment, $command->userId);
        }

        $action = PpeAction::tryFrom($command->action) ?? throw new IndustrialLaundryException('invalid_action');

        return $this->ppe->resolve($garment, $action, $command->replacementEpc, $command->reason, $command->userId);
    }
}
