<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Manufacturing\Models\MfgEdgeGateway;
use Modules\Manufacturing\Models\MfgMachineStateEvent;
use Modules\Manufacturing\Services\EdgeTelemetryService;

/**
 * A batch of machine state events from an edge gateway. The request is authenticated by `X-Gateway-Id` and the
 * HMAC-SHA256 `X-Signature` of the raw body; the gateway selects the tenant.
 */
final class IngestEdgeTelemetryRequest extends FormRequest
{
    private ?MfgEdgeGateway $gateway = null;

    /**
     * Authenticates the gateway by its HMAC signature of the raw body.
     */
    public function authorize(): bool
    {
        $this->gateway = app(EdgeTelemetryService::class)->authenticate($this->header('X-Gateway-Id'), $this->header('X-Signature'), $this->getContent());

        return $this->gateway instanceof MfgEdgeGateway;
    }

    /**
     * The authenticated gateway.
     */
    public function gateway(): MfgEdgeGateway
    {
        return $this->gateway ?? throw new \LogicException('The request was not authorized.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.event_uuid' => ['required', 'uuid'],
            'events.*.machine_id' => ['required', 'uuid'],
            'events.*.state' => ['required', 'string', 'in:'.implode(',', MfgMachineStateEvent::States)],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.payload' => ['nullable', 'array'],
        ];
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'Invalid gateway or signature.'], 401));
    }
}
