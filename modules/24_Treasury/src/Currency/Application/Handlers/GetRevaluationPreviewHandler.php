<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use Modules\Treasury\Currency\Application\Queries\GetRevaluationPreviewQuery;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;

/**
 * A revaluation of the company with its lines.
 */
final readonly class GetRevaluationPreviewHandler
{
    /**
     * @throws CurrencyDomainException 404 when the revaluation is not one of the company
     */
    public function handle(GetRevaluationPreviewQuery $query): CurrencyFxRevaluation
    {
        $revaluation = CurrencyFxRevaluation::query()->withoutGlobalScopes()->where('tenant_id', $query->tenantId)->whereNull('deleted_at')->whereKey($query->revaluationId)->first();

        if ($revaluation === null) {
            throw new CurrencyDomainException(__('currency_fx.errors.revaluation_not_found'), 'revaluation_not_found', 404);
        }

        return $revaluation->load('lines');
    }
}
