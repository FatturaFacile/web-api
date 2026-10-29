<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Models\IntercompanyFunding;

final readonly class ExecuteCashConcentrationAction
{
    public function __construct(
        private LiquidityManagementServiceInterface $liquidityService
    ) {}

    /**
     * @return array<int, IntercompanyFunding>
     */
    public function execute(string $cashPoolId, ?string $userId = null): array
    {
        return $this->liquidityService->executeCashConcentration($cashPoolId, $userId);
    }
}
