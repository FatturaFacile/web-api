<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Exceptions\AttendanceStateException;
use App\Models\AttendanceRequest;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AttendanceIdempotencyService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(): JsonResponse  $operation
     */
    public function execute(User $user, string $action, array $data, Closure $operation): JsonResponse
    {
        $normalized = $this->normalize($action, $data);
        $key = $data['idempotency_key'] ?? null;

        if ($key === null && $normalized['timestamp'] === null) {
            return $operation();
        }

        $keyHash = hash('sha256', $key !== null
            ? 'client:'.$key
            : 'timestamp:'.$action.':'.$normalized['timestamp']);
        $requestHash = hash('sha256', json_encode([
            'action' => $action,
            'data' => $normalized,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($user, $action, $operation, $keyHash, $requestHash): JsonResponse {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $previous = AttendanceRequest::where('user_id', $user->id)->where('key_hash', $keyHash)->first();

            if ($previous !== null) {
                if (! hash_equals($previous->request_hash, $requestHash)) {
                    throw new AttendanceStateException(
                        'Idempotency Conflict',
                        'This request identifier was already used with a different action or payload.'
                    );
                }

                return response()->json($previous->response_body, $previous->response_status)
                    ->header('Idempotency-Replayed', 'true');
            }

            $response = $operation();

            AttendanceRequest::create([
                'user_id' => $user->id,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'action' => $action,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
                'created_at' => now(),
            ]);

            return $response;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool|int|float|string|null>
     */
    private function normalize(string $action, array $data): array
    {
        $timestampField = match ($action) {
            'clock-in' => 'clock_in',
            'clock-out' => 'clock_out',
            default => 'occurred_at',
        };

        $normalized = [
            'timestamp' => isset($data[$timestampField])
                ? Carbon::parse($data[$timestampField])->utc()->format('Y-m-d\TH:i:s.u\Z')
                : null,
        ];

        if ($action === 'clock-in') {
            $normalized += [
                'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
                'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
                'is_simplified_record' => (bool) ($data['is_simplified_record'] ?? false),
                'notes' => $data['notes'] ?? null,
            ];
        } elseif ($action === 'clock-out') {
            $normalized += [
                'break_minutes' => isset($data['break_minutes']) ? (int) $data['break_minutes'] : null,
                'notes' => $data['notes'] ?? null,
            ];
        }

        return $normalized;
    }
}
