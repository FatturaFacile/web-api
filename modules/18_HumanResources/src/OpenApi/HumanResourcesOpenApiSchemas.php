<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'HumanResources',
    description: 'Enterprise Human Resources & Workforce Management endpoints (Employees, Organization, Leave, Time, Performance, Training)'
)]
#[OA\Schema(
    schema: 'EmployeeResource',
    title: 'Employee Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_number', type: 'string', example: 'EMP-2026-0001'),
        new OA\Property(property: 'first_name', type: 'string', example: 'Marco'),
        new OA\Property(property: 'last_name', type: 'string', example: 'Rossi'),
        new OA\Property(property: 'full_name', type: 'string', example: 'Marco Rossi'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'marco.rossi@example.com'),
        new OA\Property(property: 'status', type: 'string', enum: ['candidate', 'onboarding', 'probation', 'active', 'promoted', 'transferred', 'on_leave', 'terminated', 'retired']),
        new OA\Property(property: 'employment_type', type: 'string', enum: ['full_time', 'part_time', 'contractor', 'fixed_term', 'intern']),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date'),
        new OA\Property(property: 'base_salary', type: 'number', format: 'double', example: 55000.00),
        new OA\Property(property: 'hourly_cost_rate', type: 'number', format: 'double', example: 42.50),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    ]
)]
#[OA\Schema(
    schema: 'LeaveRequestResource',
    title: 'Leave Request Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'request_number', type: 'string', example: 'LR-202606-0001'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'leave_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'days_requested', type: 'number', format: 'double', example: 5.0),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'approved', 'rejected', 'cancelled']),
    ]
)]
final class HumanResourcesOpenApiSchemas {}
