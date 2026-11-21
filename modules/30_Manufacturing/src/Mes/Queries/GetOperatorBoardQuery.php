<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Queries;

/**
 * Operator board of a work centre (CQRS read side): the phases being worked and the operations ready to be taken over.
 */
final readonly class GetOperatorBoardQuery
{
    public function __construct(
        public string $tenantId,
        public string $workCenterId,
        public ?string $operatorId = null,
    ) {}
}
