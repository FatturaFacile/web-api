<?php

declare(strict_types=1);

namespace Modules\Copilot\Tools;

use Illuminate\Validation\Rule;
use Modules\Copilot\Contracts\MutatingActionTool;
use Modules\Copilot\DTOs\ProposedActionPreview;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\Enums\ToolType;
use Modules\HumanResources\Actions\SubmitLeaveRequestAction;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveType;

/**
 * Stages a leave request of an employee; SubmitLeaveRequestAction runs only after a human confirmed the card.
 */
final readonly class RequestEmployeeLeaveTool implements MutatingActionTool
{
    public function __construct(private SubmitLeaveRequestAction $action) {}

    public function name(): string
    {
        return 'request_employee_leave';
    }

    public function type(): ToolType
    {
        return ToolType::MutatingAction;
    }

    public function module(): string
    {
        return 'hr';
    }

    public function access(): string
    {
        return 'write';
    }

    public function description(): string
    {
        return 'Prepare a leave request for an employee. Nothing is submitted until the user confirms the proposed card.';
    }

    public function schema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'employee_id' => ['type' => 'string', 'description' => 'UUID of the employee'],
                'leave_type_id' => ['type' => 'string', 'description' => 'UUID of the leave type'],
                'start_date' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD'],
                'end_date' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD'],
                'total_hours' => ['type' => 'number', 'description' => 'Hours of leave in total'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'total_hours'],
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $context->tenantId)->whereNull('deleted_at')],
            'leave_type_id' => ['required', 'uuid', Rule::exists('hr_leave_types', 'id')->where('tenant_id', $context->tenantId)->whereNull('deleted_at')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'total_hours' => ['required', 'numeric', 'gt:0', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function preview(ToolContext $context, array $arguments): ProposedActionPreview
    {
        $employee = Employee::query()->findOrFail($arguments['employee_id']);
        $type = LeaveType::query()->findOrFail($arguments['leave_type_id']);

        return new ProposedActionPreview(
            summary: sprintf('Request %s hours of %s for %s from %s to %s', $arguments['total_hours'], $type->name, $employee->full_name, $arguments['start_date'], $arguments['end_date']),
            arguments: $arguments,
            details: [
                'employee' => $employee->full_name,
                'leave_type' => $type->name,
                'start_date' => $arguments['start_date'],
                'end_date' => $arguments['end_date'],
                'total_hours' => $arguments['total_hours'],
                'reason' => $arguments['reason'] ?? null,
            ],
            editableFields: ['start_date', 'end_date', 'total_hours', 'reason'],
        );
    }

    public function execute(ToolContext $context, array $arguments): array
    {
        $request = $this->action->execute(new SubmitLeaveRequestDTO(
            tenantId: $context->tenantId,
            employeeId: (string) $arguments['employee_id'],
            leaveTypeId: (string) $arguments['leave_type_id'],
            startDate: (string) $arguments['start_date'],
            endDate: (string) $arguments['end_date'],
            totalHours: (float) $arguments['total_hours'],
            reason: isset($arguments['reason']) ? (string) $arguments['reason'] : null,
        ), (string) $context->user->id);

        return [
            'entity_type' => 'leave_requests',
            'entity_id' => (string) $request->id,
            'label' => '#LeaveRequest-'.substr((string) $request->id, 0, 8),
            'route' => '/hr/leave-requests/'.$request->id,
        ];
    }
}
