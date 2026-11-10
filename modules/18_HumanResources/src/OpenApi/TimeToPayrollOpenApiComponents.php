<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters of the payroll transfer and of the night work balances. Schemas live in TimeToPayrollOpenApiSchemas:
 * swagger-php drops the schemas of a class that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'HrPayrollTransferYear', name: 'year', description: 'Calendar year of the period to transfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 2026))]
#[OA\Parameter(parameter: 'HrPayrollTransferMonth', name: 'month', description: 'Calendar month of the period to transfer (1 to 12)', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))]
#[OA\Parameter(parameter: 'HrNightWorkYear', name: 'year', description: 'Calendar year of the balances. Defaults to the current year', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 2026))]
#[OA\Parameter(parameter: 'HrNightWorkUserFilter', name: 'user_id', description: 'Only the balance of this user (the employee account). The balance is first re-evaluated from the recorded working time up to today', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class TimeToPayrollOpenApiComponents {}
