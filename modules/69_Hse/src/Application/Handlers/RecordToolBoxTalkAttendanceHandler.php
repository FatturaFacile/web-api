<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\Hse\Application\Commands\RecordToolBoxTalkAttendanceCommand;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Infrastructure\Models\ToolBoxTalkAttendance;
use Modules\Hse\Infrastructure\Models\ToolBoxTalkSession;
use Modules\HumanResources\Models\Employee;

/**
 * Stores a safety briefing with the signatures of its attendees. The mobile app may send the same batch again after a
 * lost connection: the briefing is found by id and a worker who already signed keeps the first signature.
 */
final class RecordToolBoxTalkAttendanceHandler
{
    public function handle(RecordToolBoxTalkAttendanceCommand $command): ToolBoxTalkSession
    {
        $ids = array_values(array_unique(array_column($command->attendances, 'employee_id')));

        if (Employee::query()->where('tenant_id', $command->tenantId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new HseException('An attendee is not an employee of the company.');
        }

        return DB::transaction(function () use ($command): ToolBoxTalkSession {
            $talk = $command->talkId === null
                ? ToolBoxTalkSession::create([
                    'tenant_id' => $command->tenantId,
                    'topic' => $command->topic,
                    'site_or_work_center' => $command->siteOrWorkCenter,
                    'checklist_points_json' => $command->checklistPoints,
                    'supervisor_employee_id' => $command->supervisorEmployeeId,
                    'conducted_at' => $command->conductedAt->setTimezone((string) config('app.timezone')),
                ])
                : (ToolBoxTalkSession::query()->where('tenant_id', $command->tenantId)->whereKey($command->talkId)->first() ?? throw new HseNotFoundException('Unknown briefing.'));

            $signed = $talk->attendances()->pluck('employee_id')->all();

            foreach ($command->attendances as $attendance) {
                if (in_array($attendance['employee_id'], $signed, true)) {
                    continue;
                }

                ToolBoxTalkAttendance::create([
                    'tenant_id' => $command->tenantId,
                    'toolbox_talk_id' => $talk->id,
                    'employee_id' => $attendance['employee_id'],
                    'signature_hash' => hash('sha256', implode('|', [$talk->id, $attendance['employee_id'], $attendance['signed_at']->toIso8601String(), $attendance['signature_payload']])),
                    'signature_image_base64_or_ref' => $attendance['signature_image'],
                    'signed_at' => $attendance['signed_at']->setTimezone((string) config('app.timezone')),
                ]);
                $signed[] = $attendance['employee_id'];
            }

            return $talk->load('attendances');
        });
    }
}
