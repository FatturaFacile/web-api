<?php

declare(strict_types=1);

namespace Modules\Payroll\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Requests and responses of legal parameters, withholding tax statements and frontier workers.
 */
#[OA\Schema(schema: 'PayrollSaveLegalParametersRequest', description: 'Request payload validated by SaveLegalParametersRequest', required: ['parameters', 'source'], type: 'object', properties: [
    new OA\Property(property: 'parameters', type: 'object'),
    new OA\Property(property: 'source', description: 'The document the values come from', type: 'string', maxLength: 2000),
])]
#[OA\Schema(schema: 'PayrollSaveFrontierYearRequest', description: 'Request payload validated by SaveFrontierYearRequest', required: ['year', 'nights_away'], type: 'object', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'nights_away', type: 'integer', minimum: 0, maximum: 366),
])]
#[OA\Schema(schema: 'PayrollWithholdingStatementRequest', description: 'Request payload validated by WithholdingStatementRequest', required: ['year', 'period_type', 'period', 'canton'], type: 'object', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'period_type', type: 'string', enum: ['monthly', 'quarterly', 'annual']),
    new OA\Property(property: 'period', type: 'integer'),
    new OA\Property(property: 'canton', type: 'string'),
])]
#[OA\Schema(schema: 'PayrollWithholdingStatementResponse', description: 'Withholding tax data by canton', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'format_status', type: 'string'),
        new OA\Property(property: 'statements', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'canton', type: 'string'),
            new OA\Property(property: 'debtor_number', type: 'string', nullable: true),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'corrections', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'total_tax', type: 'string'),
            new OA\Property(property: 'collection_commission_electronic', type: 'string'),
            new OA\Property(property: 'is_closed', type: 'boolean'),
            new OA\Property(property: 'missing_data', type: 'array', items: new OA\Items(type: 'object')),
        ])),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
    ]),
])]
#[OA\Schema(schema: 'PayrollFrontierOverview', description: 'Frontier workers of a year', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'parameters_year', type: 'integer'),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'workers', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'regime', type: 'string', enum: ['old', 'new', 'not_covered']),
            new OA\Property(property: 'suggested_regime', type: 'string'),
            new OA\Property(property: 'tariff_letter', type: 'string', nullable: true),
            new OA\Property(property: 'nights_away', type: 'integer'),
            new OA\Property(property: 'exchange_of_information', type: 'boolean'),
            new OA\Property(property: 'missing_exchange_data', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'issues', type: 'array', items: new OA\Items(type: 'string')),
        ])),
    ]),
])]
final class PayrollComplianceSchemas {}
