<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain exception for Mod 074 Leave, Permits & Absences Management.
 * Renders RFC 7807 Problem Details responses using translated `leave_management.errors.*` messages.
 */
final class LeaveManagementException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $params = [],
    ) {
        parent::__construct(__('leave_management.errors.'.$errorCode, $params));
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function validation(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_UNPROCESSABLE_ENTITY, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function conflict(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_CONFLICT, $params);
    }

    public static function invalidInpsProtocol(string $raw): self
    {
        return self::validation('invalid_inps_puc', ['puc' => $raw]);
    }

    public static function insufficientBalance(float $available, float $requested): self
    {
        return self::validation('insufficient_balance', [
            'available' => number_format($available, 2, '.', ''),
            'requested' => number_format($requested, 2, '.', ''),
        ]);
    }

    public static function overlapDetected(string $startDate, string $endDate): self
    {
        return self::conflict('overlapping_request', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    public static function staffingThresholdBreached(string $date, int $present, int $required): self
    {
        return self::conflict('staffing_threshold_breached', [
            'date' => $date,
            'present' => $present,
            'required' => $required,
        ]);
    }

    public static function invalidStateTransition(string $currentStatus, string $action): self
    {
        return self::conflict('invalid_state_transition', [
            'status' => $currentStatus,
            'action' => $action,
        ]);
    }

    public static function legge104LimitExceeded(float $usedHours, float $requestedHours, float $maxHours): self
    {
        return self::validation('legge_104_limit_exceeded', [
            'used' => number_format($usedHours, 2, '.', ''),
            'requested' => number_format($requestedHours, 2, '.', ''),
            'limit' => number_format($maxHours, 2, '.', ''),
        ]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => 'Leave Management Rule Violation',
            'status' => $this->status,
            'detail' => $this->getMessage(),
            'instance' => request()->getRequestUri(),
            'invalid_params' => [['code' => $this->errorCode, 'params' => $this->params]],
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
