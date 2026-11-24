<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Models\MfgEdgeGateway;
use Modules\Manufacturing\Models\MfgMachineSignalMap;
use Modules\Manufacturing\Services\EdgeTelemetryService;

/**
 * A batch of raw OPC UA, MQTT or Modbus messages from an edge gateway or MQTT bridge. Authenticated like the telemetry
 * endpoint: `X-Gateway-Id` and the hex HMAC-SHA256 `X-Signature` of the raw body; the gateway selects the tenant.
 */
final class IngestGatewayMessagesRequest extends FormRequest
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
            'protocol' => ['required', 'string', Rule::in(MfgMachineSignalMap::Protocols)],
            'messages' => ['required', 'array', 'min:1', 'max:500'],
            'messages.*' => ['required', 'array'],
        ];
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'Invalid gateway or signature.'], 401));
    }
}
