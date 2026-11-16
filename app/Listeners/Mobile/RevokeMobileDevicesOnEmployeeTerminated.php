<?php

declare(strict_types=1);

namespace App\Listeners\Mobile;

use App\Services\Mobile\DeviceSessionService;
use Illuminate\Support\Facades\Log;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Models\Employee;
use Throwable;

/** Offboarding: cut mobile access and request a local cache wipe on every device. */
final class RevokeMobileDevicesOnEmployeeTerminated
{
    public function __construct(private readonly DeviceSessionService $devices) {}

    public function handle(EmployeeTerminatedEvent $event): void
    {
        try {
            $userId = Employee::withoutGlobalScopes()->whereKey($event->employeeId)->value('user_id');

            if ($userId !== null) {
                $this->devices->revokeAllForUser((string) $userId, 'employee_offboarding');
            }
        } catch (Throwable $e) {
            Log::error('mobile revocation on offboarding failed: '.$e->getMessage(), ['employee_id' => $event->employeeId]);
        }
    }
}
